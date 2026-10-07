<?php

namespace App\Support\WaHub;

/**
 * A decoded hub reply (2xx or 4xx). `data` is the hub's JSON as-is, so the
 * panel routes can pass it straight through with the same status.
 */
class WaHubResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The hub's stable error code (`connection_not_found`, `unknown_variable`, …). */
    public function error(): ?string
    {
        $error = $this->data['error'] ?? null;

        return is_string($error) ? $error : null;
    }
}
