<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WinnerStreakSettingRequest;
use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Support\ResolvesBranch;
use App\Support\WinnerStreak\WinnerStreakPresenter;
use App\Support\WinnerStreak\WinnerStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Winner Streak for a branch admin. Everything here is hard-scoped to the
 * acting admin's own branch — a branch admin never sees another branch's board.
 */
class WinnerStreakController extends Controller
{
    use ResolvesBranch;

    public function __construct(protected WinnerStreakService $service)
    {
    }

    /** All three boards (daily / weekly / monthly) plus the branch's tags. */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        return response()->json(['data' => $this->service->overview($branchId)]);
    }

    /** One board, live. */
    public function show(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->assertPeriod($period);

        return response()->json(['data' => $this->service->period($branchId, $period)]);
    }

    public function update(WinnerStreakSettingRequest $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->assertPeriod($period);

        $settings = $this->service->updateSettings(
            $branchId,
            $period,
            $request->validated(),
            $request->user()->id,
        );

        return response()->json(['data' => $this->service->serializeSettings($settings)]);
    }

    /** Past (closed) cycles with their frozen winners. */
    public function history(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->assertPeriod($period);

        return response()->json([
            'data' => $this->service->history($branchId, $period, (int) $request->query('limit', 12)),
        ]);
    }

    /** Manual "Reset Now" — closes the live cycle immediately. */
    public function reset(Request $request, string $period): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->assertPeriod($period);

        $cycle = $this->service->resetNow($branchId, $period, $request->user()->id);

        return response()->json(['data' => $this->service->serializeCycle($cycle)]);
    }

    /**
     * Re-rank a closed cycle after its underlying transactions changed
     * (a deposit reversed after the freeze, say).
     */
    public function recalculate(Request $request, WinnerStreakCycle $cycle): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        if ((int) $cycle->branch_id !== $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Cycle not found.');
        }

        return response()->json(['data' => $this->service->recalculate($cycle)]);
    }

    /** Mark a winner's reward paid / skipped. */
    public function updateEntry(Request $request, WinnerStreakEntry $entry): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        if ((int) $entry->branch_id !== $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Winner not found.');
        }

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

    protected function assertPeriod(string $period): void
    {
        if (!WinnerStreakSetting::isValidPeriod($period)) {
            abort(Response::HTTP_NOT_FOUND, 'Unknown period.');
        }
    }
}
