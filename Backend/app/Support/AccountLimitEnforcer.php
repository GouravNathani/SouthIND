<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Deposit;
use App\Support\Cache\AccountCache;

class AccountLimitEnforcer
{
    /**
     * Auto-pause an account once its cumulative approved-deposit total reaches
     * the configured deposit_limit.
     *
     * Counting is lifetime cumulative (all approved deposits for the account).
     * Overshoot is allowed: the crossing approval is kept and the account is
     * paused afterwards. A `null`/empty or 0 limit means "no limit".
     *
     * @param  Deposit|null  $trigger  the approval that crossed the limit, for the audit trail
     * @return bool true when the account was paused by this call.
     */
    public static function enforce(?Account $account, ?Deposit $trigger = null): bool
    {
        if (!$account) {
            return false;
        }

        $limit = $account->deposit_limit;

        if (self::isUnlimited($limit)) {
            return false;
        }

        // Only pause accounts that are currently active.
        if ($account->status !== 'active') {
            return false;
        }

        $approvedTotal = self::approvedTotal($account);

        if ($approvedTotal < (float) $limit) {
            return false;
        }

        $account->status = 'paused';
        $account->save();

        AccountStatusAudit::record($account, 'active', AccountStatusAudit::REASON_LIMIT_REACHED, null, array_filter([
            'approved_total' => $approvedTotal,
            'limit' => (float) $limit,
            'deposit_id' => $trigger?->id,
            'approved_by' => $trigger?->approved_by,
        ], fn ($value) => $value !== null));

        self::flushCache($account);

        return true;
    }

    /**
     * Undo an auto-pause once the limit no longer applies: it was raised above
     * the approved total, or removed (null/empty or 0 = unlimited). Without this
     * an account paused by its limit stayed paused even after an admin set the
     * limit to 0, which is not what "0 = unlimited" means to the person setting it.
     *
     * Only `paused` is touched. A manual deactivation is `inactive` and is left alone.
     *
     * @return bool true when the account was re-activated by this call.
     */
    public static function resumeIfLimitLifted(?Account $account, ?Admin $actor = null): bool
    {
        if (!$account || $account->status !== 'paused') {
            return false;
        }

        $limit = $account->deposit_limit;
        $approvedTotal = self::approvedTotal($account);

        if (!self::isUnlimited($limit) && $approvedTotal >= (float) $limit) {
            return false;
        }

        $account->status = 'active';
        $account->save();

        AccountStatusAudit::record($account, 'paused', AccountStatusAudit::REASON_LIMIT_LIFTED, $actor, [
            'approved_total' => $approvedTotal,
            'limit' => self::isUnlimited($limit) ? null : (float) $limit,
        ]);

        self::flushCache($account);

        return true;
    }

    /** null/empty or 0 => unlimited. */
    public static function isUnlimited(mixed $limit): bool
    {
        return $limit === null || $limit === '' || (float) $limit <= 0;
    }

    private static function approvedTotal(Account $account): float
    {
        return (float) Deposit::query()
            ->where('account_id', $account->id)
            ->where('status', Deposit::STATUS_APPROVED)
            ->sum('amount');
    }

    private static function flushCache(Account $account): void
    {
        if ($account->branch_id) {
            AccountCache::flushForBranch((int) $account->branch_id);
        }
    }
}
