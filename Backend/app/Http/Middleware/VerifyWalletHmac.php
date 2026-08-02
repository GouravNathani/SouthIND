<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server requests coming FROM BookFlowControl.
 *
 * This is the exact mirror of the signature BookFlowWalletClient sends when we
 * call Control, and it uses the same shared WALLET_API_KEY / WALLET_API_SECRET.
 * The caller signs a canonical string of 5 lines joined by "\n":
 *
 *     METHOD \n PATH \n TIMESTAMP \n NONCE \n hex(sha256(rawBody))
 *
 * PATH carries a leading slash and no query string; rawBody is "" for GET. The
 * secret itself never travels — only the signature does.
 *
 * There is deliberately no nonce store here: the only action behind this gate
 * (clearing the cache) is idempotent, and a cache-backed nonce list would be
 * wiped by that very action. The timestamp window is what bounds replay.
 */
class VerifyWalletHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) config('wallet.api_key');
        $secret = (string) config('wallet.api_secret');

        // Fail closed when this panel has no wallet credentials configured.
        if ($expectedKey === '' || $secret === '') {
            return $this->reject('Endpoint is not configured.', 503);
        }

        $apiKey = (string) $request->header('X-Api-Key', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = (string) $request->header('X-Signature', '');

        if ($apiKey === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->reject('Missing authentication headers.');
        }

        // Constant-time comparison so the key can't be probed via timing.
        if (! hash_equals($expectedKey, $apiKey)) {
            return $this->reject('Invalid credentials.');
        }

        if (! ctype_digit($timestamp)) {
            return $this->reject('Invalid timestamp.');
        }

        $tolerance = (int) config('wallet.inbound_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject('Request timestamp outside the allowed window.');
        }

        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return $this->reject('Invalid nonce.');
        }

        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            '/'.ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);

        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $this->reject('Invalid signature.');
        }

        return $next($request);
    }

    private function reject(string $message, int $status = 401): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
