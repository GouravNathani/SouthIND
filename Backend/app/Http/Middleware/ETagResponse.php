<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for the read-heavy API.
 *
 * Three SPAs poll settings, banners, accounts and status endpoints on timers.
 * The payloads are small but constant, and almost never actually change. Hashing
 * the body and answering 304 when the client already has it turns each of those
 * polls into an empty response.
 *
 * The controller still runs — this saves bandwidth and client-side re-render, not
 * database work. Endpoints whose cost is the QUERY (deposit lists, dashboards)
 * are handled separately by cached aggregates; this is the cheap layer on top.
 */
class ETagResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldTag($request, $response)) {
            return $response;
        }

        $etag = '"'.hash('xxh128', (string) $response->getContent()).'"';
        $response->headers->set('ETag', $etag);

        // Responses are per-user (branch scoping, phone masking), so a shared
        // cache must never reuse them — only the client's own cache may.
        $response->headers->set('Cache-Control', 'private, no-cache');

        if ($this->matches($request->headers->get('If-None-Match'), $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    private function shouldTag(Request $request, Response $response): bool
    {
        // Only idempotent reads, and only plain 200s — streamed and binary
        // responses have no buffered content to hash.
        return $request->isMethodCacheable()
            && $response->getStatusCode() === 200
            && $response instanceof \Illuminate\Http\JsonResponse;
    }

    /**
     * If-None-Match is a comma-separated list and may be weak-tagged (W/"…").
     */
    private function matches(?string $header, string $etag): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
