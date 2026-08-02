<?php

namespace App\Support;

use App\Models\Account;
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
     * @return bool true when the account was paused by this call.
     */
    public static function enforce(?Account $account): bool
    {
        if (!$account) {
            return false;
        }

        $limit = $account->deposit_limit;

        // null/empty or 0 => unlimited.
        if ($limit === null || (float) $limit <= 0) {
            return false;
        }

        // Only pause accounts that are currently active.
        if ($account->status !== 'active') {
            return false;
        }

        $approvedTotal = (float) Deposit::query()
            ->where('account_id', $account->id)
            ->where('status', Deposit::STATUS_APPROVED)
            ->sum('amount');

        if ($approvedTotal < (float) $limit) {
            return false;
        }

        $account->status = 'paused';
        $account->save();

        if ($account->branch_id) {
            AccountCache::flushForBranch((int) $account->branch_id);
        }

        return true;
    }
}
