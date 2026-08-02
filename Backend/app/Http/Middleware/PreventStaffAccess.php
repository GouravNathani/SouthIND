<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventStaffAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Admin && $user->role === 'staff') {
            return response()->json([
                'message' => 'This endpoint is restricted to administrators.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
