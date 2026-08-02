<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof Admin || !$user->isSuperAdmin() || !$user->is_active) {
            return response()->json([
                'message' => 'Super admin privileges required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
