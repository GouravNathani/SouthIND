<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Admin\ClubController as BranchClubs;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Club;
use App\Support\Club\ClubAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The super admin's view of clubs: any one branch, or every branch at once
 * (create the same club everywhere, add the defaults everywhere), plus the
 * club window (how many days back activity is counted).
 */
class ClubController extends Controller
{
    public function __construct(private readonly ClubAssigner $assigner) {}

    /** `branch_id` → that branch; none → every branch, one block each. */
    public function index(Request $request): JsonResponse
    {
        if ($branchId = $this->branchFrom($request, false)) {
            return response()->json(BranchClubs::listing($branchId));
        }

        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $branches->map(fn (Branch $branch) => [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
            ] + BranchClubs::listing($branch->id))->values(),
            'meta' => ['window_days' => ClubAssigner::windowDays()],
        ]);
    }

    /** One branch (`branch_id`) or all of them (`all_branches: true`). */
    public function store(Request $request): JsonResponse
    {
        $branchIds = $this->targetBranches($request);
        $created = [];
        $skipped = [];

        foreach ($branchIds as $branchId) {
            try {
                $data = BranchClubs::validateClub($request, $branchId, null);
            } catch (ValidationException $e) {
                // With "all branches", a branch that already has this name or day
                // count is skipped rather than failing the rest.
                if (count($branchIds) === 1) {
                    throw $e;
                }
                $skipped[] = $branchId;

                continue;
            }

            $club = Club::create(['branch_id' => $branchId] + $data);
            $this->assigner->refreshBranch($branchId);
            $created[] = BranchClubs::serialize($club->loadCount('members'));
        }

        return response()->json([
            'data' => $created,
            'meta' => ['skipped_branch_ids' => $skipped],
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, Club $club): JsonResponse
    {
        $club->update(BranchClubs::validateClub($request, (int) $club->branch_id, $club->id));
        $this->assigner->refreshBranch((int) $club->branch_id);

        return response()->json(['data' => BranchClubs::serialize($club->refresh()->loadCount('members'))]);
    }

    public function destroy(Club $club): JsonResponse
    {
        $branchId = (int) $club->branch_id;
        $club->delete();
        $this->assigner->refreshBranch($branchId);

        return response()->json(['data' => ['id' => $club->id]]);
    }

    /** Add the missing default clubs to one branch or to all of them. */
    public function defaults(Request $request): JsonResponse
    {
        $created = 0;
        foreach ($this->targetBranches($request) as $branchId) {
            $created += $this->assigner->seedDefaults($branchId);
            $this->assigner->refreshBranch($branchId);
        }

        return response()->json(['data' => ['created' => $created]]);
    }

    /** Re-sort users now: one branch, or everyone when no branch is given. */
    public function refresh(Request $request): JsonResponse
    {
        $branchId = $this->branchFrom($request, false);
        $result = $branchId ? $this->assigner->refreshBranch($branchId) : $this->assigner->refreshAll();

        return response()->json(['data' => $result]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => ['window_days' => ClubAssigner::windowDays()]]);
    }

    /** A new window changes everyone's day count, so everyone is re-sorted. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'window_days' => ['required', 'integer', 'min:1', 'max:'.ClubAssigner::MAX_WINDOW_DAYS],
        ]);

        $days = ClubAssigner::setWindowDays((int) $data['window_days']);
        $this->assigner->refreshAll();

        return response()->json(['data' => ['window_days' => $days]]);
    }

    /** @return list<int> */
    private function targetBranches(Request $request): array
    {
        if ($request->boolean('all_branches')) {
            return Branch::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return [$this->branchFrom($request, true)];
    }

    private function branchFrom(Request $request, bool $required): ?int
    {
        $raw = $request->input('branch_id', $request->query('branch_id'));
        if ($raw === null || $raw === '') {
            if ($required) {
                throw ValidationException::withMessages(['branch_id' => 'Pick a branch or choose all branches.']);
            }

            return null;
        }

        $branch = Branch::query()->find((int) $raw);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Branch not found.']);
        }

        return (int) $branch->id;
    }
}
