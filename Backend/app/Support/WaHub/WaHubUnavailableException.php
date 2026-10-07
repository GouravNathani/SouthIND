<?php

namespace App\Support\WaHub;

use RuntimeException;

/**
 * The WA Hub could not answer: not configured, connection error, timeout, a
 * 5xx (including the proxy's `503 {"error":"hub_starting"}` while the daemon
 * restarts), a reply that is not JSON, or the hub refusing the signature
 * (bare `404 not_found`). Panels show "hub unreachable" and queued sends retry
 * later. Any other 4xx is NOT this — that is a real answer.
 */
class WaHubUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 0,
    ) {
        parent::__construct("WA Hub unavailable ({$reason}).");
    }
}
