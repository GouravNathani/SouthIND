<?php

namespace App\Support\Wallet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HMAC-SHA256 signed client for the BookFlowControl wallet API.
 *
 * Canonical string signed per request (5 lines joined by "\n"):
 *   METHOD
 *   /api/v1/wallets/<id>/...   (leading slash, NO query string)
 *   <unix-timestamp-seconds>
 *   <nonce>
 *   <sha256-hex-of-raw-body>   (sha256("") for GET)
 *
 * X-Signature = hmac_sha256(secret, canonical) in lowercase hex. The secret only
 * signs locally and is never sent. Every call returns a structured array and
 * never throws, so callers (message sends) never break on a wallet outage.
 */
class BookFlowWalletClient
{
    private string $baseUrl;

    private ?string $apiKey;

    private ?string $apiSecret;

    private float $timeout;

    public function __construct(private readonly WalletIdentity $identity)
    {
        $this->baseUrl = (string) config('wallet.base_url');
        $this->apiKey = config('wallet.api_key');
        $this->apiSecret = config('wallet.api_secret');
        $this->timeout = (float) config('wallet.timeout', 4);
    }

    public function walletId(): ?string
    {
        return $this->identity->walletId();
    }

    /**
     * Can we actually call Control? A self wallet resolves an id (the Super
     * Admin's number) but has no credentials, so this stays false and every
     * request short-circuits to `not_configured` — which is exactly what puts
     * WalletService onto its self-wallet fallback.
     */
    public function configured(): bool
    {
        return $this->baseUrl !== ''
            && $this->identity->configuredId() !== null
            && !empty($this->apiKey)
            && !empty($this->apiSecret);
    }

    /**
     * GET balance + rates + per-message costs for the configured wallet.
     *
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    public function getBalance(): array
    {
        return $this->request('GET', "/api/v1/wallets/{$this->walletId()}/balance");
    }

    /**
     * POST a deduction of coins (money, down to 0.01). Idempotent via $reference.
     *
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    public function deduct(int|float $coins, string $reference): array
    {
        return $this->request('POST', "/api/v1/wallets/{$this->walletId()}/deduct", [
            // Send a plain 2-decimal number so the JSON matches decimal(16,2).
            'amount' => round((float) $coins, 2),
            'reference' => $reference,
        ]);
    }

    /**
     * GET the ledger (query string is NOT signed, only the path).
     *
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    public function getTransactions(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->request('GET', "/api/v1/wallets/{$this->walletId()}/transactions", null, ['limit' => $limit]);
    }

    /**
     * GET a pending Control-driven SuperAdmin password reset for this wallet.
     * When pending, `data.password` is the plaintext temp password to apply.
     *
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    public function getAdminReset(): array
    {
        return $this->request("GET", "/api/v1/wallets/{$this->walletId()}/admin-reset");
    }

    /**
     * Tell Control the reset has been applied (clears it there).
     */
    public function consumeAdminReset(): array
    {
        return $this->request("POST", "/api/v1/wallets/{$this->walletId()}/admin-reset/consume");
    }

    /**
     * GET whether a cache-clear is queued for this wallet.
     */
    public function getCacheClear(): array
    {
        return $this->request("GET", "/api/v1/wallets/{$this->walletId()}/cache-clear");
    }

    /**
     * Tell Control the cache-clear has been run.
     */
    public function consumeCacheClear(): array
    {
        return $this->request("POST", "/api/v1/wallets/{$this->walletId()}/cache-clear/consume");
    }

    /**
     * Report how much this panel still owes (days whose payout failed), so an
     * operator can see it on the Control wallet page and clear it by hand.
     *
     * This never moves money — Control only stores the figure.
     *
     * @param  array<string, mixed>  $payload  owed_total, by_month, as_of
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    public function reportOwed(array $payload): array
    {
        return $this->request('POST', "/api/v1/wallets/{$this->walletId()}/owed", $payload);
    }

    /**
     * Sign and send one request. Returns a normalized envelope; a transport
     * failure surfaces as ok=false, status=0, code='transport_error'.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, status: int, data: ?array, code: ?string}
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'code' => 'not_configured'];
        }

        $method = strtoupper($method);
        $rawBody = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $rawBody);

        // Sign the PATH ONLY — never the query string.
        $canonical = implode("\n", [$method, $path, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, (string) $this->apiSecret);

        $headers = [
            'X-Api-Key' => $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'Accept' => 'application/json',
        ];

        $url = $this->baseUrl . $path;

        try {
            $pending = Http::withHeaders($headers)->timeout($this->timeout);

            if ($method === 'GET') {
                $response = $pending->get($url, $query);
            } else {
                // Send the EXACT bytes we hashed (do not let the client re-encode).
                $response = $pending->withBody($rawBody, 'application/json')->send($method, $url);
            }
        } catch (\Throwable $e) {
            Log::warning('Wallet API transport error.', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'data' => null, 'code' => 'transport_error'];
        }

        $json = $response->json();
        $ok = $response->successful() && is_array($json) && ($json['success'] ?? false) === true;

        return [
            'ok' => $ok,
            'status' => $response->status(),
            'data' => is_array($json) ? ($json['data'] ?? null) : null,
            'code' => is_array($json) ? ($json['error']['code'] ?? null) : null,
        ];
    }
}
