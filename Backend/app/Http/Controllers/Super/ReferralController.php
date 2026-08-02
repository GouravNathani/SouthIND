<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Concerns\HandlesReferral;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Referral\ReferralReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Referral & commission for the owner. Same surface as the Admin controller,
 * but the branch comes from a `branch_id` parameter — and with no branch_id the
 * landing response is a branch-wise roll-up of the whole programme.
 */
class ReferralController extends Controller
{
    use HandlesReferral;

    public function index(Request $request): JsonResponse
    {
        if (!$request->filled('branch_id')) {
            return response()->json(['data' => [
                'branches' => ReferralReports::branchSummaries(),
            ]]);
        }

        $branchId = $this->branchIdFor($request);

        return response()->json(['data' => [
            'branch_id' => $branchId,
            'overview' => ReferralReports::overview($branchId),
            'settings' => \App\Support\Referral\ReferralPresenter::settings($this->referral()->settings($branchId)),
            'leaderboard' => ReferralReports::leaderboard($branchId),
        ]]);
    }

    protected function branchIdFor(Request $request): int
    {
        $branchId = (int) ($request->input('branch_id') ?: $request->query('branch_id'));

        if (!$branchId || !Branch::query()->whereKey($branchId)->exists()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid branch_id is required.');
        }

        return $branchId;
    }
}
