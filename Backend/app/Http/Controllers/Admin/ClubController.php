<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use App\Support\Club\ClubAssigner;
use App\Support\ResolvesBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * A branch admin's clubs. The admin sets name, colour and day count; the
 * system decides who is in which club (ClubAssigner), so every change here
 * re-sorts the branch's users straight away.
 */
class ClubController extends Controller
{
    use ResolvesBranch;

    public function __construct(private readonly ClubAssigner $assigner) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        return response()->json(self::listing($branchId));
    }

    public function store(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $data = self::validateClub($request, $branchId, null);

        $club = Club::create(['branch_id' => $branchId] + $data);
        $this->assigner->refreshBranch($branchId);

        return response()->json(['data' => self::serialize($club->loadCount('members'))], Response::HTTP_CREATED);
    }

    public function update(Request $request, Club $club): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        self::ensureBranch($club, $branchId);

        $club->update(self::validateClub($request, $branchId, $club->id));
        $this->assigner->refreshBranch($branchId);

        return response()->json(['data' => self::serialize($club->refresh()->loadCount('members'))]);
    }

    public function destroy(Request $request, Club $club): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        self::ensureBranch($club, $branchId);

        $club->delete();
        $this->assigner->refreshBranch($branchId);

        return response()->json(['data' => ['id' => $club->id]]);
    }

    /** Add the default clubs this branch is missing (Diamond, Gold, Silver, Comeback). */
    public function defaults(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        $this->assigner->seedDefaults($branchId);
        $this->assigner->refreshBranch($branchId);

        return response()->json(self::listing($branchId));
    }

    /** Re-sort this branch's users now instead of waiting for the night run. */
    public function refresh(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        $this->assigner->refreshBranch($branchId);

        return response()->json(self::listing($branchId));
    }

    // ── Shared with the super admin controller ──────────────────────────────

    /** Clubs of one branch, highest first, plus the window and member totals. */
    public static function listing(int $branchId): array
    {
        $clubs = Club::query()
            ->where('branch_id', $branchId)
            ->withCount('members')
            ->orderByDesc('min_active_days')
            ->get();

        $users = User::query()->where('branch_id', $branchId)->count();
        $members = (int) $clubs->sum('members_count');

        return [
            'data' => $clubs->map(fn (Club $club) => self::serialize($club))->values(),
            'meta' => [
                'window_days' => ClubAssigner::windowDays(),
                'users_total' => $users,
                'members_total' => $members,
                'without_club' => max(0, $users - $members),
                'refreshed_at' => optional(ClubMember::query()->where('branch_id', $branchId)->max('updated_at'), fn ($at) => \Illuminate\Support\Carbon::parse($at)->toIso8601String()),
            ],
        ];
    }

    public static function serialize(Club $club): array
    {
        return [
            'id' => $club->id,
            'branch_id' => $club->branch_id,
            'name' => $club->name,
            'color' => $club->color,
            'min_active_days' => $club->min_active_days,
            'members_count' => (int) ($club->members_count ?? 0),
        ];
    }

    /**
     * Name unique in the branch, a hex colour, and a day count from 1 to the
     * window that no other club of the branch already uses.
     */
    public static function validateClub(Request $request, int $branchId, ?int $ignoreId): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('clubs', 'name')->where(fn ($q) => $q->where('branch_id', $branchId))->ignore($ignoreId),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'min_active_days' => [
                'required', 'integer', 'min:1', 'max:'.ClubAssigner::windowDays(),
                Rule::unique('clubs', 'min_active_days')->where(fn ($q) => $q->where('branch_id', $branchId))->ignore($ignoreId),
            ],
        ], [
            'min_active_days.unique' => 'Another club of this branch already uses this many days.',
            'min_active_days.max' => 'Days cannot be more than the club window (:max days).',
        ]);
    }

    public static function ensureBranch(Club $club, int $branchId): void
    {
        if ((int) $club->branch_id !== $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Club not found.');
        }
    }
}
