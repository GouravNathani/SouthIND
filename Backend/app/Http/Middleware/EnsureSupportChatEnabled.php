<?php

namespace App\Http\Middleware;

use App\Support\Cache\GlobalSettingCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the user and branch-admin support chat endpoints while the super
 * admin has switched support chat off. The `code` lets the panels tell this
 * apart from other 403s and stop polling instead of retrying.
 */
class EnsureSupportChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!GlobalSettingCache::supportChatEnabled()) {
            return response()->json([
                'message' => 'Support chat is turned off.',
                'code' => 'support_chat_disabled',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
