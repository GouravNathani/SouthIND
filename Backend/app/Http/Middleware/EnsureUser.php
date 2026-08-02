<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'message' => 'User authentication required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
