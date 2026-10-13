<?php

namespace App\Http\Middleware;

use App\Support\Cache\GlobalSettingCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the admin and super WA Hub endpoints while the super admin has the
 * WA Hub switched off. The `code` lets the panels tell this apart from other
 * 403s and hide the WhatsApp pages instead of retrying.
 */
class EnsureWaHubEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!GlobalSettingCache::waHubEnabled()) {
            return response()->json([
                'message' => 'WhatsApp Hub is turned off.',
                'code' => 'wa_hub_disabled',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
