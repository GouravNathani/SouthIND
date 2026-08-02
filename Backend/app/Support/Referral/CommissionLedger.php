<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\CommissionEntry;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes commission_entries and moves an account balance.
 *
 * Balances are never incremented in place. Every write appends a ledger row and
 * then RECOMPUTES the account from the ledger inside the same transaction, with
 * the account row locked. That costs one extra aggregate per write — trivial at
 * agent volumes — and buys the property that a balance can never drift from its
 * history, whatever crashes or retries happen in between.
 */
class CommissionLedger
{
    /**
     * Append a row and refresh the agent's account.
     */
    public static function post(User $agent, array $attributes): CommissionEntry
    {
        return DB::transaction(function () use ($agent, $attributes) {
            $account = CommissionAccount::forUser($agent);

            // Serialise concurrent writes for this agent (two deposits approved
            // at once, or a payout racing an accrual).
            CommissionAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            $entry = CommissionEntry::create(array_merge([
                'agent_id' => $agent->id,
                'branch_id' => $agent->branch_id,
                'status' => CommissionEntry::STATUS_PENDING,
                'level' => 1,
            ], $attributes));

            self::recompute($agent->id);

            return $entry;
        });
    }

    /**
     * Change an entry's status (release, pay, void) and refresh the account.
     */
    public static function transition(CommissionEntry $entry, string $status, array $extra = []): CommissionEntry
    {
        return DB::transaction(function () use ($entry, $status, $extra) {
            $account = CommissionAccount::query()
                ->where('user_id', $entry->agent_id)
                ->lockForUpdate()
                ->first();

            $entry->fill($extra);
            $entry->status = $status;
            $entry->save();

            if ($account) {
                self::recompute((int) $entry->agent_id);
            }

            return $entry->refresh();
        });
    }

    /**
     * Rebuild one agent's cached totals from their ledger. Safe to call at any
     * time; `referral:rebuild` calls it for every agent.
     */
    public static function recompute(int $agentId): ?CommissionAccount
    {
        $account = CommissionAccount::query()->where('user_id', $agentId)->first();

        if (!$account) {
            return null;
        }

        // `available` counts PAID rows too. Only payouts ever reach `paid`, and
        // they are negative — a settled payout has to keep reducing the balance,
        // otherwise approving it would hand the money straight back.
        $sums = CommissionEntry::query()
            ->where('agent_id', $agentId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 0) AS available_balance,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS pending_balance,
                COALESCE(SUM(CASE WHEN type IN (?, ?) AND status <> ? THEN amount ELSE 0 END), 0) AS lifetime_earned,
                COALESCE(SUM(CASE WHEN type = ? AND status = ? THEN -amount ELSE 0 END), 0) AS lifetime_paid,
                COALESCE(SUM(CASE WHEN type = ? AND status <> ? THEN amount ELSE 0 END), 0) AS lifetime_adjusted
            ", [
                CommissionEntry::STATUS_AVAILABLE,
                CommissionEntry::STATUS_PAID,
                CommissionEntry::STATUS_PENDING,
                CommissionEntry::TYPE_ACCRUAL,
                CommissionEntry::TYPE_BONUS,
                CommissionEntry::STATUS_VOID,
                CommissionEntry::TYPE_PAYOUT,
                CommissionEntry::STATUS_PAID,
                CommissionEntry::TYPE_ADJUSTMENT,
                CommissionEntry::STATUS_VOID,
            ])
            ->first();

        $account->fill([
            'available_balance' => (float) $sums->available_balance,
            'pending_balance' => (float) $sums->pending_balance,
            'lifetime_earned' => (float) $sums->lifetime_earned,
            'lifetime_paid' => (float) $sums->lifetime_paid,
            'lifetime_adjusted' => (float) $sums->lifetime_adjusted,
        ]);

        $account->save();

        return $account;
    }

    /**
     * Refresh the team counters for an agent (size and lifetime volume).
     *
     * Volume drives the tier ladder, so it is recomputed from approved deposits
     * rather than accumulated — a deposit deleted by an admin must reduce it.
     */
    public static function refreshTeamStats(int $agentId): ?CommissionAccount
    {
        $account = CommissionAccount::query()->where('user_id', $agentId)->first();

        if (!$account) {
            return null;
        }

        $memberIds = User::query()->where('referred_by', $agentId)->pluck('id');

        $volume = $memberIds->isEmpty() ? 0.0 : (float) Deposit::query()
            ->whereIn('user_id', $memberIds)
            ->where('status', Deposit::STATUS_APPROVED)
            ->sum('amount');

        $activeCount = $memberIds->isEmpty() ? 0 : Deposit::query()
            ->whereIn('user_id', $memberIds)
            ->where('status', Deposit::STATUS_APPROVED)
            ->distinct('user_id')
            ->count('user_id');

        $account->team_count = $memberIds->count();
        $account->team_active_count = $activeCount;
        $account->team_deposit_total = $volume;
        $account->save();

        return $account;
    }

    /**
     * Sum of everything an agent has earned in a calendar month — the input to
     * the monthly cap. Adjustments count, so a correction genuinely frees cap.
     */
    public static function earnedInMonth(int $agentId, \DateTimeInterface $when): float
    {
        return (float) CommissionEntry::query()
            ->where('agent_id', $agentId)
            ->whereIn('type', [CommissionEntry::TYPE_ACCRUAL, CommissionEntry::TYPE_BONUS, CommissionEntry::TYPE_ADJUSTMENT])
            ->where('status', '<>', CommissionEntry::STATUS_VOID)
            ->whereYear('created_at', (int) $when->format('Y'))
            ->whereMonth('created_at', (int) $when->format('m'))
            ->sum('amount');
    }
}
