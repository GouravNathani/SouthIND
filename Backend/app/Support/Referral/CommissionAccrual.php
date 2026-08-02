<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\CommissionEntry;
use App\Models\Deposit;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Push\UserPushNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Turns approved deposits into commission.
 *
 * Called from the deposit status endpoints. Every path here is defensive: a
 * failure to accrue must never break the admin's ability to approve a deposit,
 * so the caller wraps this in a try/catch and we log rather than throw.
 */
class CommissionAccrual
{
    /**
     * A deposit just moved to approved — pay the referral chain.
     *
     * Idempotent: the unique index on (deposit_id, agent_id, level) means a
     * retried or double-clicked approval writes nothing the second time.
     *
     * @return array<int, CommissionEntry> the entries written
     */
    public static function onDepositApproved(Deposit $deposit): array
    {
        if ($deposit->status !== Deposit::STATUS_APPROVED || !$deposit->user_id || !$deposit->branch_id) {
            return [];
        }

        $settings = ReferralSetting::forBranch((int) $deposit->branch_id);

        if (!$settings->enabled) {
            return [];
        }

        $member = User::query()->find($deposit->user_id);

        if (!$member || !$member->referred_by) {
            return [];
        }

        $written = [];
        $agent = User::query()->find($member->referred_by);
        $level = 1;

        while ($agent && $level <= ($settings->level2_enabled ? 2 : 1)) {
            $entry = self::accrueFor($deposit, $member, $agent, $settings, $level);

            if ($entry) {
                $written[] = $entry;
            }

            $level++;
            $agent = $agent->referred_by ? User::query()->find($agent->referred_by) : null;
        }

        // Team volume feeds the tier ladder, so refresh it after the deposit
        // lands — the agent may have just crossed a rung.
        if ($member->referred_by) {
            CommissionLedger::refreshTeamStats((int) $member->referred_by);
        }

        return $written;
    }

    /**
     * One (deposit, agent, level) accrual, or null when it is not earned.
     */
    protected static function accrueFor(
        Deposit $deposit,
        User $member,
        User $agent,
        ReferralSetting $settings,
        int $level,
    ): ?CommissionEntry {
        if (!$agent->isEarningAgent()) {
            return null;
        }

        // Cross-branch chains cannot earn, even if one exists from bad data.
        if ((int) $agent->branch_id !== (int) $deposit->branch_id) {
            return null;
        }

        $skip = self::ineligibleReason($deposit, $member, $settings);

        if ($skip !== null) {
            return null;
        }

        // Already accrued (retry / double click).
        $exists = CommissionEntry::query()
            ->where('deposit_id', $deposit->id)
            ->where('agent_id', $agent->id)
            ->where('level', $level)
            ->exists();

        if ($exists) {
            return null;
        }

        $rate = CommissionRate::resolve($agent, $settings, $level);
        $amount = round(((float) $deposit->amount) * $rate['percent'] / 100, 2);

        if ($amount <= 0) {
            return null;
        }

        $capNote = null;

        if ($settings->per_deposit_cap > 0 && $amount > (float) $settings->per_deposit_cap) {
            $amount = (float) $settings->per_deposit_cap;
            $capNote = 'Capped at the per-deposit limit.';
        }

        if ($settings->monthly_cap_per_agent > 0) {
            $earned = CommissionLedger::earnedInMonth((int) $agent->id, now());
            $room = (float) $settings->monthly_cap_per_agent - $earned;

            if ($room <= 0) {
                return null;
            }

            if ($amount > $room) {
                $amount = round($room, 2);
                $capNote = 'Capped at the monthly limit.';
            }
        }

        if ($amount <= 0) {
            return null;
        }

        $holdHours = (int) $settings->holding_hours;
        $availableAt = $holdHours > 0 ? now()->addHours($holdHours) : now();

        $entry = CommissionLedger::post($agent, [
            'type' => CommissionEntry::TYPE_ACCRUAL,
            'status' => $holdHours > 0
                ? CommissionEntry::STATUS_PENDING
                : CommissionEntry::STATUS_AVAILABLE,
            'from_user_id' => $member->id,
            'deposit_id' => $deposit->id,
            'level' => $level,
            'base_amount' => (float) $deposit->amount,
            'percent' => $rate['percent'],
            'tier_label' => $rate['label'],
            'amount' => $amount,
            'available_at' => $availableAt,
            'released_at' => $holdHours > 0 ? null : now(),
            'deposit_status_at_accrual' => $deposit->status,
            'deposit_status_now' => $deposit->status,
            'from_display_name' => $member->name,
            'from_display_play_id' => $member->play_id ?: $member->unique_number,
            'note' => $capNote,
            'meta' => ['rate_source' => $rate['source']],
        ]);

        CommissionAccount::query()
            ->where('user_id', $agent->id)
            ->update([
                'tier_label' => $rate['label'],
                'tier_percent' => $rate['percent'],
                'last_accrual_at' => now(),
            ]);

        if ($settings->notify_on_commission && $holdHours === 0) {
            try {
                UserPushNotifier::notifyCommissionEarned((int) $agent->id, number_format($amount, 2), $member->name);
            } catch (\Throwable $e) {
                Log::warning('Commission push failed.', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }
        }

        return $entry;
    }

    /**
     * Why this deposit does not earn, or null when it does.
     */
    protected static function ineligibleReason(Deposit $deposit, User $member, ReferralSetting $settings): ?string
    {
        if ($settings->min_deposit_amount > 0 && (float) $deposit->amount < (float) $settings->min_deposit_amount) {
            return 'Below the minimum deposit amount.';
        }

        // A referrer attached after the fact only earns from the attach moment,
        // unless the branch has explicitly opted into paying retroactively.
        if ($member->commission_from && $deposit->created_at && $deposit->created_at->lt($member->commission_from)) {
            return 'Deposit predates the referral link.';
        }

        if ($settings->first_deposit_only) {
            $earlier = Deposit::query()
                ->where('user_id', $member->id)
                ->where('status', Deposit::STATUS_APPROVED)
                ->where('id', '<>', $deposit->id)
                ->where('created_at', '<=', $deposit->created_at ?? now())
                ->exists();

            if ($earlier) {
                return 'Not the first approved deposit.';
            }
        }

        return null;
    }

    /**
     * A deposit left the approved state after we had already accrued on it.
     *
     * By design this moves NO money: the accrual stands and the row is flagged
     * so it surfaces in the admin's review queue, where a human decides whether
     * to write a correcting adjustment. Silent clawbacks are how agents lose
     * trust in a programme.
     */
    public static function onDepositStatusChanged(Deposit $deposit): void
    {
        $entries = CommissionEntry::query()
            ->where('deposit_id', $deposit->id)
            ->where('type', CommissionEntry::TYPE_ACCRUAL)
            ->get();

        foreach ($entries as $entry) {
            $entry->deposit_status_now = $deposit->status;

            if ($deposit->status === Deposit::STATUS_APPROVED) {
                // Back to approved — clear the flag, nothing to review.
                $entry->flagged_at = null;
                $entry->flag_reason = null;
            } elseif (!$entry->flagged_at) {
                $entry->flagged_at = now();
                $entry->flag_reason = 'Deposit is now ' . $deposit->status . ' — commission already credited.';
                $entry->resolved_at = null;
                $entry->resolved_by = null;
            }

            $entry->save();
        }

        if ($deposit->user_id) {
            $agentId = User::query()->where('id', $deposit->user_id)->value('referred_by');

            if ($agentId) {
                CommissionLedger::refreshTeamStats((int) $agentId);
            }
        }
    }

    /**
     * The deposit row itself is about to be removed. Same policy as a status
     * change: flag, never claw back — but record what the deposit WAS, because
     * after the delete there is nothing left to look up.
     */
    public static function onDepositDeleted(Deposit $deposit): void
    {
        $entries = CommissionEntry::query()
            ->where('deposit_id', $deposit->id)
            ->where('type', CommissionEntry::TYPE_ACCRUAL)
            ->get();

        foreach ($entries as $entry) {
            $entry->deposit_status_now = 'deleted';

            if (!$entry->flagged_at) {
                $entry->flagged_at = now();
                $entry->flag_reason = 'Deposit was deleted — commission already credited.';
                $entry->resolved_at = null;
                $entry->resolved_by = null;
            }

            $entry->meta = array_merge((array) $entry->meta, [
                'deleted_deposit' => [
                    'amount' => (float) $deposit->amount,
                    'utr_number' => $deposit->utr_number,
                    'created_at' => optional($deposit->created_at)->toIso8601String(),
                ],
            ]);

            $entry->save();
        }
    }

    /**
     * Move pending commission whose holding window has elapsed into available.
     * Driven by the `referral:tick` schedule.
     *
     * @return array{released: int, flagged: int}
     */
    public static function releaseDue(int $limit = 500): array
    {
        $due = CommissionEntry::query()
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->where('type', CommissionEntry::TYPE_ACCRUAL)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        $released = 0;
        $flagged = 0;

        foreach ($due as $entry) {
            $deposit = $entry->deposit_id ? Deposit::query()->find($entry->deposit_id) : null;

            // The deposit was undone during the holding window — this is exactly
            // what the window is for. Still no automatic clawback: it simply
            // never leaves pending, and an admin sees it flagged.
            if ($deposit && $deposit->status !== Deposit::STATUS_APPROVED) {
                $entry->deposit_status_now = $deposit->status;

                if (!$entry->flagged_at) {
                    $entry->flagged_at = now();
                    $entry->flag_reason = 'Deposit became ' . $deposit->status . ' during the holding period.';
                    $flagged++;
                }

                $entry->save();
                continue;
            }

            $washout = self::washoutReason($entry, $deposit);

            if ($washout !== null) {
                if (!$entry->flagged_at) {
                    $entry->flagged_at = now();
                    $entry->flag_reason = $washout;
                    $flagged++;
                }

                $entry->save();
                continue;
            }

            CommissionLedger::transition($entry, CommissionEntry::STATUS_AVAILABLE, [
                'released_at' => now(),
            ]);

            $released++;

            $settings = ReferralSetting::forBranch((int) $entry->branch_id);

            if ($settings->notify_on_commission) {
                try {
                    UserPushNotifier::notifyCommissionEarned(
                        (int) $entry->agent_id,
                        number_format((float) $entry->amount, 2),
                        $entry->from_display_name,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Commission release push failed.', ['entry_id' => $entry->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return ['released' => $released, 'flagged' => $flagged];
    }

    /**
     * Deposit-in, withdraw-straight-back-out is the cheapest way to farm a
     * percentage. If the branch configured a washout window and the member
     * pulled at least the deposit amount back out inside it, hold the
     * commission for review instead of releasing it.
     */
    protected static function washoutReason(CommissionEntry $entry, ?Deposit $deposit): ?string
    {
        if (!$deposit || !$entry->from_user_id) {
            return null;
        }

        $settings = ReferralSetting::forBranch((int) $entry->branch_id);
        $hours = (int) $settings->washout_hours;

        if ($hours <= 0) {
            return null;
        }

        $from = $deposit->approved_at ?? $deposit->created_at ?? $entry->created_at;

        $withdrawn = (float) Withdrawal::query()
            ->where('user_id', $entry->from_user_id)
            ->where('status', Withdrawal::STATUS_APPROVED)
            ->whereBetween('created_at', [$from, (clone $from)->addHours($hours)])
            ->sum('amount');

        if ($withdrawn >= (float) $deposit->amount) {
            return "Member withdrew ₹" . number_format($withdrawn, 2) . " within {$hours}h of this deposit.";
        }

        return null;
    }
}
