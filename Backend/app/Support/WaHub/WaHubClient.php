<?php

namespace App\Support\WaHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HMAC-SHA256 signed client for the WA Hub API (`/v1/*`).
 *
 * Canonical string signed per request (5 lines joined by "\n"):
 *   METHOD
 *   /v1/...                    (leading slash, NO query string)
 *   <unix-timestamp-seconds>
 *   <nonce>                    (32 hex chars, single use)
 *   <sha256-hex-of-raw-body>   (sha256("") for GET / DELETE)
 *
 * X-Signature = hmac_sha256(secret, canonical) in lowercase hex. The secret
 * only signs locally and is never sent.
 *
 * A 2xx or 4xx comes back as a WaHubResponse (decoded JSON + status) so the
 * panel routes can pass it through. Not configured, connection errors,
 * timeouts, 5xx (incl. `503 hub_starting`), non-JSON replies and a refused
 * signature (bare `404 not_found`) throw WaHubUnavailableException.
 *
 * Never log secrets, MPINs, message text or full phone numbers from here.
 */
class WaHubClient
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private string $baseUrl;

    private ?string $apiKey;

    private ?string $apiSecret;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.wahub.url'), '/');
        $this->apiKey = config('services.wahub.key') ?: null;
        $this->apiSecret = config('services.wahub.secret') ?: null;
        $this->timeout = max(1, (int) config('services.wahub.timeout', 8));
    }

    /** Without the tenant key + secret the hub is never called. */
    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== null && $this->apiSecret !== null;
    }

    /** The hub sender key for a branch's number. */
    public static function connectionKey(int $branchId): string
    {
        return 'branch-' . $branchId;
    }

    /** The branch id behind a `branch-{id}` key, or null for any other key. */
    public static function branchIdFromKey(?string $key): ?int
    {
        return is_string($key) && preg_match('/^branch-(\d+)$/', $key, $m) ? (int) $m[1] : null;
    }

    // ---- Connections ------------------------------------------------------

    public function connections(): WaHubResponse
    {
        return $this->request('GET', '/v1/connections');
    }

    public function connection(string $key): WaHubResponse
    {
        return $this->request('GET', $this->connectionPath($key));
    }

    /** @param  array<string, mixed>  $data  label, header, footer, enabled */
    public function saveConnection(string $key, array $data): WaHubResponse
    {
        return $this->request('PUT', $this->connectionPath($key), $data);
    }

    /** @param  array<string, mixed>  $data  label */
    public function link(string $key, array $data = []): WaHubResponse
    {
        return $this->request('POST', $this->connectionPath($key, '/link'), $data);
    }

    public function unlink(string $key): WaHubResponse
    {
        return $this->request('POST', $this->connectionPath($key, '/unlink'), []);
    }

    public function optOuts(string $key): WaHubResponse
    {
        return $this->request('GET', $this->connectionPath($key, '/opt-outs'));
    }

    // ---- Events -----------------------------------------------------------

    public function events(string $key): WaHubResponse
    {
        return $this->request('GET', $this->connectionPath($key, '/events'));
    }

    /** @param  array<string, mixed>  $data  enabled, body (null = reset to default) */
    public function updateEvent(string $key, string $event, array $data): WaHubResponse
    {
        return $this->request('PUT', $this->connectionPath($key, '/events/' . rawurlencode($event)), $data);
    }

    /** @param  array<string, mixed>  $data  body, vars */
    public function previewEvent(string $key, string $event, array $data): WaHubResponse
    {
        return $this->request('POST', $this->connectionPath($key, '/events/' . rawurlencode($event) . '/preview'), $data);
    }

    /** @param  array{to: string, text: string}  $data */
    public function test(string $key, array $data): WaHubResponse
    {
        return $this->request('POST', $this->connectionPath($key, '/test'), $data);
    }

    // ---- Messages ---------------------------------------------------------

    /**
     * Queue one event message. Same idempotency_key again → the same message,
     * never sent twice.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(array $data): WaHubResponse
    {
        return $this->request('POST', '/v1/messages', $data);
    }

    /** @param  array<string, mixed>  $query */
    public function messages(array $query): WaHubResponse
    {
        return $this->request('GET', '/v1/messages', null, $query);
    }

    // ---- Saved templates --------------------------------------------------

    public function templates(string $key): WaHubResponse
    {
        return $this->request('GET', $this->connectionPath($key, '/templates'));
    }

    /** @param  array<string, mixed>  $data  name, body */
    public function createTemplate(string $key, array $data): WaHubResponse
    {
        return $this->request('POST', $this->connectionPath($key, '/templates'), $data);
    }

    /** @param  array<string, mixed>  $data  name, body */
    public function updateTemplate(string $key, int $id, array $data): WaHubResponse
    {
        return $this->request('PUT', $this->connectionPath($key, '/templates/' . $id), $data);
    }

    public function deleteTemplate(string $key, int $id): WaHubResponse
    {
        return $this->request('DELETE', $this->connectionPath($key, '/templates/' . $id));
    }

    // ---- Campaigns --------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    public function createCampaign(array $data): WaHubResponse
    {
        return $this->request('POST', '/v1/campaigns', $data);
    }

    /** @param  array<string, mixed>  $query  connection, page, per_page */
    public function campaigns(array $query): WaHubResponse
    {
        return $this->request('GET', '/v1/campaigns', null, $query);
    }

    public function campaign(int $id): WaHubResponse
    {
        return $this->request('GET', '/v1/campaigns/' . $id);
    }

    public function cancelCampaign(int $id): WaHubResponse
    {
        return $this->request('POST', '/v1/campaigns/' . $id . '/cancel', []);
    }

    // ---- Usage ------------------------------------------------------------

    /** @param  array<string, mixed>  $query  connection */
    public function usage(array $query = []): WaHubResponse
    {
        return $this->request('GET', '/v1/usage', null, $query);
    }

    // ---- Signing ----------------------------------------------------------

    /**
     * The exact body bytes sent (and hashed). An empty array goes out as `{}`
     * — every hub body is a JSON object.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encodeBody(array $data): string
    {
        return (string) json_encode($data === [] ? new \stdClass() : $data, self::JSON_FLAGS);
    }

    public static function signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $rawBody): string
    {
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $rawBody)]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Sign and send one request.
     *
     * @param  array<string, mixed>|null  $body  null = no body (GET / DELETE)
     * @param  array<string, mixed>  $query  never signed, only the path is
     *
     * @throws WaHubUnavailableException
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): WaHubResponse
    {
        if (!$this->configured()) {
            throw new WaHubUnavailableException('not_configured');
        }

        $method = strtoupper($method);
        $rawBody = $body === null ? '' : self::encodeBody($body);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $headers = [
            'X-Api-Key' => (string) $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => self::signature((string) $this->apiSecret, $method, $path, $timestamp, $nonce, $rawBody),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $options = $query === [] ? [] : ['query' => $query];

        try {
            $pending = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout(min($this->timeout, 5))
                // A redirect would re-send to a path that was not signed.
                ->withoutRedirecting();

            if ($rawBody !== '') {
                // Send the EXACT bytes we hashed (do not let the client re-encode).
                $pending = $pending->withBody($rawBody, 'application/json');
            }

            $response = $pending->send($method, $this->baseUrl . $path, $options);
        } catch (ConnectionException $e) {
            $this->logFailure($method, $path, 0, 'connection', $e);

            throw new WaHubUnavailableException('connection');
        } catch (\Throwable $e) {
            $this->logFailure($method, $path, 0, 'transport', $e);

            throw new WaHubUnavailableException('transport');
        }

        $status = $response->status();
        $json = $response->json();

        if ($status >= 500) {
            $reason = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'http_' . $status;
            $this->logFailure($method, $path, $status, $reason);

            throw new WaHubUnavailableException($reason, $status);
        }

        if (!is_array($json)) {
            $this->logFailure($method, $path, $status, 'bad_response');

            throw new WaHubUnavailableException('bad_response', $status);
        }

        // A bare `404 {"error":"not_found"}` is the hub refusing the request
        // itself (wrong key / secret, clock skew, unknown route) — missing
        // records always come back with their own `*_not_found` code. Nothing
        // the panel user can fix, so it counts as unavailable (and a queued
        // send retries, e.g. while a rotated secret is being rolled out).
        if ($status === 404 && ($json['error'] ?? null) === 'not_found') {
            $this->logFailure($method, $path, $status, 'request_rejected');

            throw new WaHubUnavailableException('request_rejected', $status);
        }

        return new WaHubResponse($status, $json);
    }

    private function connectionPath(string $key, string $suffix = ''): string
    {
        return '/v1/connections/' . rawurlencode($key) . $suffix;
    }

    /**
     * Path (no query string: `q` may hold part of a number) + status + reason
     * only. Transport messages carry the full URL, so their query is stripped.
     */
    private function logFailure(string $method, string $path, int $status, string $reason, ?\Throwable $e = null): void
    {
        $context = [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'reason' => $reason,
        ];

        if ($e !== null) {
            $context['error'] = class_basename($e) . ': ' . preg_replace('/\?\S*/', '', $e->getMessage());
        }

        Log::warning('WA Hub request failed.', $context);
    }
}
