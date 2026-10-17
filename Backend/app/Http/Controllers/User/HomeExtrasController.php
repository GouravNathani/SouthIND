<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Ticker\TickerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Small extras for the user home page: the branch's running strip and the
 * user's club badge. Either is null when there is nothing to show.
 */
class HomeExtrasController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $membership = $user->clubMembership()->with('club:id,name,color')->first();

        return response()->json([
            'ticker' => TickerService::publicView($user->branch_id ? (int) $user->branch_id : null),
            'club' => $membership && $membership->club ? [
                'name' => $membership->club->name,
                'color' => $membership->club->color,
                'active_days' => (int) $membership->active_days,
            ] : null,
        ]);
    }
}
