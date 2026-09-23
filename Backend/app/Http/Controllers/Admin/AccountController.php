<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountRequest;
use App\Http\Resources\Admin\AccountResource;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Deposit;
use App\Support\AccountLimitEnforcer;
use App\Support\AccountStatusAudit;
use App\Support\Cache\AccountCache;
use App\Support\ResolvesBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;


class AccountController extends Controller
{
    use ResolvesBranch;

    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());

        $accounts = AccountCache::rememberForBranch($branchId, function () use ($branchId) {
            return Account::query()
                ->with(['creator:id,name,role', 'statusChangedBy:id,name,role'])
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get();
        });

        // Last-used timestamps live outside the cached collection so a fresh
        // deposit reorders the list immediately instead of waiting for the TTL.
        $lastUsed = Deposit::query()
            ->where('branch_id', $branchId)
            ->selectRaw('account_id, MAX(created_at) as last_used_at')
            ->groupBy('account_id')
            ->pluck('last_used_at', 'account_id');

        $accounts = $accounts
            ->each(function (Account $account) use ($lastUsed): void {
                $account->setAttribute('last_used_at', $lastUsed[$account->id] ?? null);
            })
            // Active accounts first, then most recently used, never-used last.
            ->sort(function (Account $a, Account $b) {
                $rank = fn (Account $account) => strtolower((string) $account->status) === 'active' ? 0 : 1;
                if ($rank($a) !== $rank($b)) {
                    return $rank($a) <=> $rank($b);
                }

                $aUsed = $a->getAttribute('last_used_at');
                $bUsed = $b->getAttribute('last_used_at');
                if ($aUsed !== $bUsed) {
                    if ($aUsed === null) {
                        return 1;
                    }
                    if ($bUsed === null) {
                        return -1;
                    }

                    return strcmp((string) $bUsed, (string) $aUsed);
                }

                return strcasecmp((string) $a->name, (string) $b->name);
            })
            ->values();

        return AccountResource::collection($accounts);
    }

    public function store(AccountRequest $request)
    {
        $ownerId = $this->resolveOwnerAdminId($request->user());
        $branchId = $this->resolveBranchId($request->user());

        $data = $request->validated();

        if ($request->hasFile('scanner_image')) {
            $data['logo_path'] = $this->storeScannerImage($request);
        }

        unset($data['scanner_image']);

        $account = Account::create([
            ...$data,
            'created_by' => $request->user()->id,
            'owner_admin_id' => $ownerId,
            'branch_id' => $branchId,
        ]);

        AccountCache::flushForBranch($branchId);

        return response()->json([
            'message' => 'Account created',
            'account' => new AccountResource($this->withAudit($account)),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Account $account)
    {
        $this->ensureOwnership($account, $request->user());

        return new AccountResource($this->withAudit($account));
    }

    public function update(AccountRequest $request, Account $account)
    {
        $this->ensureOwnership($account, $request->user());

        $data = $request->validated();

        if ($request->hasFile('scanner_image')) {
            $this->deleteScannerImage($account->logo_path);
            $data['logo_path'] = $this->storeScannerImage($request);
        }

        unset($data['scanner_image']);

        $fromStatus = $account->status;

        $account->update($data);

        if ($account->status !== $fromStatus) {
            AccountStatusAudit::record($account, $fromStatus, AccountStatusAudit::REASON_MANUAL, $request->user());
        } elseif (array_key_exists('deposit_limit', $data)) {
            // Raising the limit, or clearing it (0/empty = unlimited), releases
            // an account the limit had auto-paused.
            AccountLimitEnforcer::resumeIfLimitLifted($account, $request->user());
        }

        AccountCache::flushForBranch($this->resolveBranchId($request->user()));

        return new AccountResource($this->withAudit($account));
    }

    public function destroy(Request $request, Account $account): Response
    {
        $this->ensureOwnership($account, $request->user());

        $branchId = $this->resolveBranchId($request->user());

        $account->delete();

        AccountCache::flushForBranch($branchId);

        return response()->noContent();
    }

    /**
     * Who added the account and who last changed its status, for the panel's
     * badges. The list eager-loads the same relations.
     */
    private function withAudit(Account $account): Account
    {
        return $account->load(['creator:id,name,role', 'statusChangedBy:id,name,role']);
    }

    protected function resolveOwnerAdminId(Admin $actor): int
    {
        if ($actor->role === 'staff' && $actor->parent_id) {
            return $actor->parent_id;
        }

        return $actor->id;
    }

    protected function ensureOwnership(Account $account, Admin $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $account->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Account not found.');
        }
    }

    protected function storeScannerImage(Request $request): string
    {
        $path = $request->file('scanner_image')->store('scanner-images', 'public');

        return Storage::url($path);
    }

    protected function deleteScannerImage(?string $storedPath): void
    {
        if (!$storedPath) {
            return;
        }

        $path = parse_url($storedPath, PHP_URL_PATH) ?: $storedPath;
        $relativePath = preg_replace('#^/?storage/#', '', (string) $path);
        $relativePath = ltrim((string) $relativePath, '/');

        if ($relativePath && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }
}
