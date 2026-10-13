<?php

namespace App\Http\Controllers\Concerns;

use App\Support\WaHub\WaHubResponse;
use App\Support\WaHub\WaHubUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel WA Hub routes answer with the hub's own JSON and status (4xx
 * included, so `error` codes reach the SPA unchanged, e.g. `403
 * tenant_disabled`). Hub down, restarting, 5xx, refused signature or not
 * configured → 502 `wa_hub_unavailable`.
 */
trait ProxiesWaHub
{
    /**
     * @param  callable(): (WaHubResponse|JsonResponse)  $call
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $transform  applied to 2xx bodies only
     */
    protected function proxy(callable $call, ?callable $transform = null): JsonResponse
    {
        try {
            $response = $call();
        } catch (WaHubUnavailableException) {
            return $this->hubUnavailable();
        }

        if ($response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->data;
        if ($transform !== null && $response->successful()) {
            $data = $transform($data);
        }

        return response()->json($data, $response->status);
    }

    protected function hubUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'WhatsApp Hub is not reachable right now. Please try again in a minute.',
            'code' => 'wa_hub_unavailable',
        ], Response::HTTP_BAD_GATEWAY);
    }

    /**
     * Hub list filters the SPA may pass through; anything else is dropped.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected function hubFilters(Request $request, array $keys): array
    {
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$key] = trim((string) $value);
            }
        }

        return $filters;
    }
}
