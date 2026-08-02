<?php

namespace App\Http\Middleware;

use App\Support\Cache\GlobalSettingCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserPanelAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $setting = GlobalSettingCache::current();
        if ($setting && $setting->user_panel_maintenance_enabled) {
            return response()->json([
                'message' => 'User panel is under maintenance. Please try again later.',
                'maintenance_scope' => 'user_panel',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $next($request);
    }
}
