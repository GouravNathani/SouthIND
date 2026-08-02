<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof Admin || $user->role !== 'staff' || !$user->is_active) {
            return response()->json([
                'message' => 'Staff authentication required.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$user->resolveBranchId()) {
            return response()->json([
                'message' => 'Branch assignment required.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->branch && !$user->branch->is_active) {
            return response()->json([
                'message' => 'Branch is disabled.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
