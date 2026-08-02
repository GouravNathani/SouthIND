<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Concerns\AttachesReferralAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStatusUpdateRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Http\Requests\Super\UpdateUserBranchRequest;
use App\Http\Resources\Super\UserResource;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UserManagementController extends Controller
{
    use AttachesReferralAgent;

    public function index(Request $request)
    {
        $query = User::query()->with('branch');
        $query->select('users.*')
            ->selectSub(
                Deposit::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('play_id', 'users.play_id')
                    ->where('status', Deposit::STATUS_APPROVED),
                'deposit_approved_total'
            )
            ->selectSub(
                Withdrawal::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('play_id', 'users.play_id')
                    ->where('status', Withdrawal::STATUS_APPROVED),
                'withdrawal_approved_total'
            );

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($status = $request->query('status')) {
            if (in_array($status, User::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $pattern = '%' . $search . '%';
                $query->where('phone', 'like', $pattern)
                    ->orWhere('name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('unique_number', 'like', $pattern)
                    ->orWhere('play_id', 'like', $pattern)
                    ->orWhere('mpin', 'like', $pattern)
                    ->orWhere('status', 'like', $pattern);
            });
        }

        $sortBy = $request->query('sort_by');
        if (is_string($sortBy) && $sortBy !== '') {
            switch ($sortBy) {
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'last_used':
                    // Most recently online first; users who never came online sink last.
                    $query->orderByRaw('last_seen_at IS NULL, last_seen_at DESC')
                        ->orderBy('created_at', 'desc');
                    break;
                case 'profit':
                    $query->orderByRaw('(deposit_approved_total - withdrawal_approved_total) desc');
                    break;
                case 'loss':
                    $query->orderByRaw('(deposit_approved_total - withdrawal_approved_total) asc');
                    break;
                default:
                    $query->orderBy('created_at', 'desc');
                    break;
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        $perPage = min($perPage, 100);

        $users = $query->paginate($perPage)->withQueryString();

        return UserResource::collection($users)
            ->additional(['user_stats' => $this->userStats($request->query('branch_id'))]);
    }

    /**
     * Headline counts for the Users page stat boxes. Scoped to a branch when
     * ?branch_id is set, otherwise all branches. "Online now" = last seen
     * within 2 minutes (matches the list's live dot).
     *
     * @return array{active_now: int, seen_24h: int, new_7d: int, logged_in: int}
     */
    private function userStats($branchId): array
    {
        $base = fn () => User::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $onlineNow = $base()->where('last_seen_at', '>=', now()->subMinutes(2))->count();

        return [
            'active_now' => $onlineNow,
            'seen_24h' => $base()->where('last_seen_at', '>=', now()->subDay())->count(),
            'new_7d' => $base()->where('created_at', '>=', now()->subDays(7))->count(),
            // Per product decision, "logged in" mirrors "online now".
            'logged_in' => $onlineNow,
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('users', 'phone')->where(
                    fn ($query) => $query->where('branch_id', $request->input('branch_id')),
                ),
            ],
            'play_id' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:8'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            // "Referral Agent" on the create form — the new user is attached to
            // this agent's team right after creation.
            'agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $agentId = $validated['agent_id'] ?? null;

        $phone = (string) $validated['phone'];
        $name = ! blank($validated['name'] ?? null)
            ? $validated['name']
            : 'User ' . (($digits = preg_replace('/\D+/', '', $phone)) !== ''
                ? substr($digits, -6)
                : Str::upper(Str::random(4)));
        $password = ! blank($validated['password'] ?? null)
            ? $validated['password']
            : Str::random(12);
        $mpin = User::generateMpin();
        $warning = $this->resolvePlayIdWarning($validated['play_id'] ?? null, null);

        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'play_id' => $validated['play_id'] ?? null,
            'password' => $password,
            'mpin' => $mpin,
            'status' => User::STATUS_ACTIVE,
            'branch_id' => $validated['branch_id'],
        ]);

        // A referral agent was chosen: link the user now so the agent's team and
        // commission start counting from the very first deposit. Attach failures
        // never undo the user — they come back as a warning instead.
        if ($agentId) {
            $warning = $this->attachToAgent(
                $user,
                (int) $agentId,
                (int) $validated['branch_id'],
                (int) $request->user()->id,
                $warning,
            );
        }

        $payload = [
            'message' => 'User created successfully.',
            'user' => new UserResource($user->refresh()->load('branch')),
            'credentials' => [
                'phone' => $user->phone,
                'user_id' => $user->unique_number,
                'mpin' => $mpin,
                'password' => $password,
            ],
        ];
        if ($warning) {
            $payload['warning'] = $warning;
        }

        return response()->json($payload, Response::HTTP_CREATED);
    }

    public function show(User $user)
    {
        $userWithTotals = User::query()
            ->select('users.*')
            ->selectSub(
                Deposit::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('play_id', 'users.play_id')
                    ->where('status', Deposit::STATUS_APPROVED),
                'deposit_approved_total'
            )
            ->selectSub(
                Withdrawal::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('play_id', 'users.play_id')
                    ->where('status', Withdrawal::STATUS_APPROVED),
                'withdrawal_approved_total'
            )
            ->whereKey($user->id)
            ->firstOrFail();

        return new UserResource($userWithTotals->load(['branch', 'tags']));
    }

    public function update(User $user, UserUpdateRequest $request)
    {
        $validated = $request->validated();
        $warning = $this->resolvePlayIdWarning($validated['play_id'] ?? null, $user->id);
        $user->update($validated);

        $payload = [
            'message' => 'User updated successfully.',
            'user' => new UserResource($user->refresh()->load(['branch', 'tags'])),
        ];
        if ($warning) {
            $payload['warning'] = $warning;
        }

        return response()->json($payload, Response::HTTP_OK);
    }

    public function updateStatus(User $user, UserStatusUpdateRequest $request)
    {
        $user->update($request->validated());

        return response()->json([
            'message' => 'User status updated successfully.',
            'user' => new UserResource($user->refresh()->load(['branch', 'tags'])),
        ], Response::HTTP_OK);
    }

    public function resetMpin(User $user)
    {
        $mpin = User::generateMpin();
        $user->update([
            'mpin' => $mpin,
            'mpin_failed_attempts' => 0,
            'mpin_locked_at' => null,
        ]);

        return response()->json([
            'message' => 'MPIN generated successfully.',
            'user_id' => $user->unique_number,
            'mpin' => $mpin,
        ], Response::HTTP_OK);
    }

    public function updateBranch(User $user, UpdateUserBranchRequest $request)
    {
        $user->update($request->validated());

        return response()->json([
            'message' => 'User branch updated successfully.',
            'user' => new UserResource($user->refresh()->load(['branch', 'tags'])),
        ], Response::HTTP_OK);
    }

    protected function resolvePlayIdWarning(?string $playId, ?int $ignoreUserId): ?string
    {
        $normalized = is_string($playId) ? trim($playId) : '';
        if ($normalized === '') {
            return null;
        }

        $query = User::query()->where('play_id', $normalized);
        if ($ignoreUserId) {
            $query->where('id', '!=', $ignoreUserId);
        }
        $duplicate = $query->first();

        return $duplicate
            ? 'Warning: this User ID is already linked to another account.'
            : null;
    }
}
