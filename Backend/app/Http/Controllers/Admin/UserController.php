<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AttachesReferralAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStatusUpdateRequest;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\ResolvesBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    use AttachesReferralAgent;
    use ResolvesBranch;

    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());
        $query = User::query()->where('branch_id', $branchId)->with('branch');
        $canViewProfit = (bool) ($request->user()?->allow_profit_view ?? false);
        if ($canViewProfit) {
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
        }
        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        $perPage = min($perPage, 100);

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
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $users = $query->latest('created_at')->paginate($perPage)->withQueryString();

        return UserResource::collection($users)
            ->additional(['user_stats' => $this->userStats($branchId)]);
    }

    /**
     * Headline counts for the Admin Users page stat boxes, scoped to the admin's
     * branch. "active_now" = last seen within 2 minutes (matches the live dot).
     *
     * @return array{active_now: int, seen_24h: int, new_24h: int, total: int}
     */
    private function userStats($branchId): array
    {
        $base = fn () => User::query()->where('branch_id', $branchId);

        return [
            'active_now' => (clone $base())->where('last_seen_at', '>=', now()->subMinutes(2))->count(),
            'seen_24h' => (clone $base())->where('last_seen_at', '>=', now()->subDay())->count(),
            'new_24h' => (clone $base())->where('created_at', '>=', now()->subDay())->count(),
            'total' => $base()->count(),
        ];
    }

    public function show(User $user)
    {
        $actor = request()->user();
        $this->ensureOwnership($user, $actor);

        $canViewProfit = (bool) ($actor?->allow_profit_view ?? false);
        if ($canViewProfit) {
            $user = User::query()
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
        }

        return new UserResource($user->load(['branch', 'tags']));
    }

    public function store(UserStoreRequest $request)
    {
        $validated = $request->validated();
        // Optional "Referral Agent" picked on the create form — the new user is
        // attached to that agent's team right after creation.
        $agentId = $validated['agent_id'] ?? null;
        unset($validated['agent_id']);
        $branchId = $this->resolveBranchId($request->user());
        $mpin = User::generateMpin();
        $phone = (string) ($validated['phone'] ?? '');
        $requestedName = $validated['name'] ?? null;
        $requestedPassword = $validated['password'] ?? null;
        $name = $this->resolveName($phone, $requestedName);
        $password = $this->resolvePassword($requestedPassword);
        $warning = $this->resolvePlayIdWarning($validated['play_id'] ?? null, null);

        $user = User::create([
            ...$validated,
            'name' => $name,
            'password' => $password,
            'mpin' => $mpin,
            'status' => User::STATUS_ACTIVE,
            'branch_id' => $branchId,
        ]);

        // A referral agent was chosen: link the user now so the agent's team and
        // commission start counting from the very first deposit. Attach failures
        // never undo the user — they come back as a warning instead.
        if ($agentId) {
            $warning = $this->attachToAgent($user, (int) $agentId, $branchId, (int) $request->user()->id, $warning);
        }

        $payload = [
            'message' => 'User created successfully.',
            'user' => new UserResource($user->refresh()),
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

    public function updateStatus(User $user, UserStatusUpdateRequest $request)
    {
        $this->ensureOwnership($user, $request->user());
        $user->update($request->validated());

        return response()->json([
            'message' => 'User status updated successfully.',
            'user' => new UserResource($user->refresh()),
        ], Response::HTTP_OK);
    }

    public function update(User $user, UserUpdateRequest $request)
    {
        $this->ensureOwnership($user, $request->user());
        $validated = $request->validated();
        $warning = $this->resolvePlayIdWarning($validated['play_id'] ?? null, $user->id);
        $user->update($validated);

        $payload = [
            'message' => 'User updated successfully.',
            'user' => new UserResource($user->refresh()),
        ];
        if ($warning) {
            $payload['warning'] = $warning;
        }

        return response()->json($payload, Response::HTTP_OK);
    }

    protected function resolveName(string $phone, ?string $providedName = null): string
    {
        if (!blank($providedName)) {
            return $providedName;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        $suffix = $digits !== '' ? substr($digits, -6) : Str::upper(Str::random(4));

        return 'User ' . $suffix;
    }

    protected function resolvePassword(?string $providedPassword = null): string
    {
        if (!blank($providedPassword)) {
            return $providedPassword;
        }

        return Str::random(12);
    }

    protected function ensureOwnership(User $user, $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $user->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'User not found.');
        }
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
