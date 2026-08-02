<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\WinnerStreakSettingRequest;
use App\Models\Branch;
use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Support\WinnerStreak\WinnerStreakPresenter;
use App\Support\WinnerStreak\WinnerStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Winner Streak for the owner. Same surface as the Admin controller, but the
 * branch comes from a `branch_id` parameter instead of the actor, and with no
 * branch_id the response is a branch-wise summary of every board.
 */
class WinnerStreakController extends Controller
{
    public function __construct(protected WinnerStreakService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('branch_id')) {
            $branchId = $this->resolveBranch($request);

            return response()->json([
                'data' => $this->service->overview($branchId) + ['branch_id' => $branchId],
            ]);
        }

        return response()->json(['data' => ['branches' => $this->service->branchSummaries()]]);
    }

    public function show(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranch($request);
        $this->assertPeriod($period);

        return response()->json(['data' => $this->service->period($branchId, $period)]);
    }

    public function update(WinnerStreakSettingRequest $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranch($request);
        $this->assertPeriod($period);

        $settings = $this->service->updateSettings(
            $branchId,
            $period,
            $request->validated(),
            $request->user()->id,
        );

        return response()->json(['data' => $this->service->serializeSettings($settings)]);
    }

    public function history(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranch($request);
        $this->assertPeriod($period);

        return response()->json([
            'data' => $this->service->history($branchId, $period, (int) $request->query('limit', 12)),
        ]);
    }

    public function reset(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranch($request);
        $this->assertPeriod($period);

        $cycle = $this->service->resetNow($branchId, $period, $request->user()->id);

        return response()->json(['data' => $this->service->serializeCycle($cycle)]);
    }

    /**
     * Re-rank a closed cycle after its underlying transactions changed.
     */
    public function recalculate(Request $request, WinnerStreakCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->service->recalculate($cycle)]);
    }

    public function updateEntry(Request $request, WinnerStreakEntry $entry): JsonResponse
    {
        $data = $request->validate([
            'reward_status' => ['sometimes', 'string', 'in:' . implode(',', WinnerStreakEntry::REWARD_STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('reward_status', $data)) {
            $entry->reward_status = $data['reward_status'];
            $entry->paid_by = $data['reward_status'] === WinnerStreakEntry::REWARD_PAID
                ? $request->user()->id
                : null;
            $entry->paid_at = $data['reward_status'] === WinnerStreakEntry::REWARD_PAID
                ? now()
                : null;
        }

        if (array_key_exists('notes', $data)) {
            $entry->notes = $data['notes'];
        }

        $entry->save();

        return response()->json(['data' => WinnerStreakPresenter::forAdmin($entry->refresh())]);
    }

    protected function resolveBranch(Request $request): int
    {
        $branchId = (int) ($request->input('branch_id') ?: $request->query('branch_id'));

        if (!$branchId || !Branch::query()->whereKey($branchId)->exists()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid branch_id is required.');
        }

        return $branchId;
    }

    protected function assertPeriod(string $period): void
    {
        if (!WinnerStreakSetting::isValidPeriod($period)) {
            abort(Response::HTTP_NOT_FOUND, 'Unknown period.');
        }
    }
}
