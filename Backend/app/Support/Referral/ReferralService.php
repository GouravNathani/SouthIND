<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\Deposit;
use App\Models\ReferralAudit;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Push\UserPushNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Everything the panels do to the referral programme: promote agents, attach
 * referrers, correct balances, process payouts, and read it all back.
 *
 * Branch scoping is enforced at the top of every mutating method rather than
 * left to the controllers, so a missed check in one panel cannot leak an agent
 * from another branch.
 */
class ReferralService
{
    // ---- Settings -------------------------------------------------------

    public function settings(int $branchId): ReferralSetting
    {
        return ReferralSetting::forBranch($branchId);
    }

    public function updateSettings(int $branchId, array $data, ?int $adminId): ReferralSetting
    {
        $settings = ReferralSetting::forBranch($branchId);
        $before = $settings->only(array_keys($data));

        $settings->fill($data);
        $settings->updated_by = $adminId;
        $settings->save();

        ReferralAudit::record(
            branchId: $branchId,
            userId: 0,
            action: ReferralAudit::ACTION_SETTINGS,
            actorId: $adminId,
            reason: 'Referral settings updated.',
            meta: ['before' => $before, 'after' => $settings->only(array_keys($data))],
        );

        return $settings->refresh();
    }

    // ---- Agents ---------------------------------------------------------

    /**
     * Promote a user to agent: mint their code and open their Commission
     * Account. Idempotent — calling it on an existing agent just returns them.
     */
    public function promote(User $user, ?int $adminId, string $actorType = 'admin'): User
    {
        if (!$user->branch_id) {
            throw new RuntimeException('User has no branch, so they cannot become an agent.');
        }

        $already = $user->isAgent();

        $user->user_type = User::TYPE_AGENT;

        if (blank($user->agent_status)) {
            $user->agent_status = User::AGENT_ACTIVE;
        }

        $user->save();

        ReferralCode::ensureFor($user);
        CommissionAccount::forUser($user->refresh());
        CommissionLedger::refreshTeamStats((int) $user->id);

        if (!$already) {
            ReferralAudit::record(
                branchId: (int) $user->branch_id,
                userId: (int) $user->id,
                action: ReferralAudit::ACTION_PROMOTE,
                actorType: $actorType,
                actorId: $adminId,
                reason: 'Promoted to agent.',
            );
        }

        return $user->refresh();
    }

    /**
     * Demote an agent back to a plain user. The account and ledger stay —
     * money already earned is still owed.
     */
    public function demote(User $user, ?int $adminId, ?string $reason = null): User
    {
        $user->user_type = User::TYPE_USER;
        $user->save();

        CommissionAccount::query()
            ->where('user_id', $user->id)
            ->update(['status' => CommissionAccount::STATUS_SUSPENDED]);

        ReferralAudit::record(
            branchId: (int) $user->branch_id,
            userId: (int) $user->id,
            action: ReferralAudit::ACTION_DEMOTE,
            actorId: $adminId,
            reason: $reason ?: 'Demoted to user.',
        );

        return $user->refresh();
    }

    /**
     * Suspend / resume earning without touching the account or its history.
     */
    public function setAgentStatus(User $user, string $status, ?int $adminId, ?string $reason = null): User
    {
        $user->agent_status = $status;
        $user->save();

        CommissionAccount::query()
            ->where('user_id', $user->id)
            ->update([
                'status' => $status === User::AGENT_SUSPENDED
                    ? CommissionAccount::STATUS_SUSPENDED
                    : CommissionAccount::STATUS_ACTIVE,
            ]);

        ReferralAudit::record(
            branchId: (int) $user->branch_id,
            userId: (int) $user->id,
            action: $status === User::AGENT_SUSPENDED
                ? ReferralAudit::ACTION_SUSPEND
                : ReferralAudit::ACTION_RESUME,
            actorId: $adminId,
            reason: $reason,
        );

        return $user->refresh();
    }

    public function setOverrideRate(User $user, ?float $percent, ?int $adminId): User
    {
        $user->commission_percent_override = $percent;
        $user->save();

        ReferralAudit::record(
            branchId: (int) $user->branch_id,
            userId: (int) $user->id,
            action: ReferralAudit::ACTION_SETTINGS,
            actorId: $adminId,
            reason: $percent === null
                ? 'Custom commission rate cleared.'
                : "Custom commission rate set to {$percent}%.",
        );

        return $user->refresh();
    }

    // ---- Attribution ----------------------------------------------------

    /**
     * Link a user to an agent. This is "Admin uske referral ko usse connect kar
     * sakta hai" — and the moment that decides money, so it is guarded, audited
     * and it promotes the referrer to agent on the spot.
     *
     * @param  bool  $retroactive  null = follow the branch setting.
     */
    public function attach(
        User $user,
        User $referrer,
        ?int $adminId,
        ?string $reason = null,
        string $actorType = 'admin',
        ?bool $retroactive = null,
    ): User {
        $settings = ReferralSetting::forBranch((int) $user->branch_id);

        $problem = ReferralGuard::checkAttach($user, $referrer, $settings);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $previous = $user->referred_by ? (int) $user->referred_by : null;

        if ($previous === (int) $referrer->id) {
            return $user;
        }

        $payRetro = $retroactive ?? (bool) $settings->retroactive_on_attach;

        DB::transaction(function () use ($user, $referrer, $adminId, $reason, $actorType, $previous, $payRetro) {
            $user->referred_by = $referrer->id;
            $user->referred_at = now();
            $user->referral_source = $actorType === 'user' ? 'signup' : 'admin';
            // Retroactive means "no cutoff" — every past deposit is fair game.
            $user->commission_from = $payRetro ? null : now();
            $user->save();

            ReferralAudit::record(
                branchId: (int) $user->branch_id,
                userId: (int) $user->id,
                action: $previous ? ReferralAudit::ACTION_REATTACH : ReferralAudit::ACTION_ATTACH,
                oldReferrerId: $previous,
                newReferrerId: (int) $referrer->id,
                actorType: $actorType,
                actorId: $adminId,
                reason: $reason,
                meta: ['retroactive' => $payRetro],
            );
        });

        $this->promote($referrer, $adminId, $actorType === 'user' ? 'system' : $actorType);

        CommissionLedger::refreshTeamStats((int) $referrer->id);

        if ($previous) {
            CommissionLedger::refreshTeamStats($previous);
        }

        if ($settings->notify_on_join) {
            try {
                UserPushNotifier::notifyReferralJoined((int) $referrer->id, $user->name);
            } catch (\Throwable $e) {
                Log::warning('Referral join push failed.', ['error' => $e->getMessage()]);
            }
        }

        // Pay out anything the new link now covers. Only ever reaches past
        // deposits when the attach was explicitly retroactive, because
        // commission_from otherwise blocks them.
        $this->backfill($user);

        return $user->refresh();
    }

    public function detach(User $user, ?int $adminId, ?string $reason = null): User
    {
        $previous = $user->referred_by ? (int) $user->referred_by : null;

        if (!$previous) {
            return $user;
        }

        $user->referred_by = null;
        $user->referred_at = null;
        $user->referral_source = null;
        $user->commission_from = null;
        $user->save();

        ReferralAudit::record(
            branchId: (int) $user->branch_id,
            userId: (int) $user->id,
            action: ReferralAudit::ACTION_DETACH,
            oldReferrerId: $previous,
            actorId: $adminId,
            reason: $reason,
        );

        CommissionLedger::refreshTeamStats($previous);

        return $user->refresh();
    }

    /**
     * Run every eligible approved deposit of a member through the accrual
     * engine. Safe to call repeatedly — accrual is idempotent per deposit.
     */
    public function backfill(User $member): int
    {
        $deposits = Deposit::query()
            ->where('user_id', $member->id)
            ->where('status', Deposit::STATUS_APPROVED)
            ->orderBy('created_at')
            ->get();

        $count = 0;

        foreach ($deposits as $deposit) {
            try {
                $count += count(CommissionAccrual::onDepositApproved($deposit));
            } catch (\Throwable $e) {
                Log::warning('Referral backfill failed for deposit.', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ---- Manual money ---------------------------------------------------

    /**
     * The admin's correction tool. Positive credits, negative debits — this is
     * how a flagged accrual on a reversed deposit gets undone, deliberately and
     * on the record, instead of automatically.
     */
    public function adjust(User $agent, float $amount, ?string $note, ?int $adminId, ?CommissionEntry $against = null): CommissionEntry
    {
        if ($amount == 0.0) {
            throw new RuntimeException('Adjustment amount cannot be zero.');
        }

        $entry = CommissionLedger::post($agent, [
            'type' => CommissionEntry::TYPE_ADJUSTMENT,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'amount' => round($amount, 2),
            'available_at' => now(),
            'released_at' => now(),
            'note' => $note,
            'created_by' => $adminId,
            'from_user_id' => $against?->from_user_id,
            'meta' => $against ? ['against_entry_id' => $against->id] : null,
        ]);

        if ($against) {
            $against->resolved_at = now();
            $against->resolved_by = $adminId;
            $against->save();
        }

        ReferralAudit::record(
            branchId: (int) $agent->branch_id,
            userId: (int) $agent->id,
            action: ReferralAudit::ACTION_ADJUST,
            actorId: $adminId,
            reason: $note,
            meta: ['amount' => $amount, 'entry_id' => $entry->id, 'against_entry_id' => $against?->id],
        );

        return $entry;
    }

    /** Dismiss a flag without moving money — "checked, the credit is fine". */
    public function resolveFlag(CommissionEntry $entry, ?int $adminId, ?string $note = null): CommissionEntry
    {
        $entry->resolved_at = now();
        $entry->resolved_by = $adminId;

        if (filled($note)) {
            $entry->note = trim($entry->note . "\n" . $note);
        }

        $entry->save();

        return $entry->refresh();
    }

    // ---- Payouts --------------------------------------------------------

    /**
     * An agent asks for their money. The amount is held immediately as a
     * negative ledger entry, so the same balance cannot be requested twice.
     */
    public function requestPayout(User $agent, float $amount, string $method, array $destination = []): CommissionPayout
    {
        $settings = ReferralSetting::forBranch((int) $agent->branch_id);

        if (!$settings->enabled) {
            throw new RuntimeException('Referral programme is not active.');
        }

        if (!$agent->isEarningAgent()) {
            throw new RuntimeException('This agent account cannot request a payout.');
        }

        if ($method === CommissionPayout::METHOD_PLAY && !$settings->payout_to_play_enabled) {
            throw new RuntimeException('Play credit payouts are disabled.');
        }

        if ($method !== CommissionPayout::METHOD_PLAY && !$settings->payout_to_bank_enabled) {
            throw new RuntimeException('Cash payouts are disabled.');
        }

        if ($amount < (float) $settings->min_payout_amount) {
            throw new RuntimeException('Minimum payout is ₹' . number_format((float) $settings->min_payout_amount, 2) . '.');
        }

        return DB::transaction(function () use ($agent, $amount, $method, $destination) {
            $account = CommissionAccount::query()
                ->where('user_id', $agent->id)
                ->lockForUpdate()
                ->first();

            if (!$account || (float) $account->available_balance < $amount) {
                throw new RuntimeException('Not enough available balance.');
            }

            $payout = CommissionPayout::create([
                'agent_id' => $agent->id,
                'branch_id' => $agent->branch_id,
                'amount' => round($amount, 2),
                'method' => $method,
                'upi_id' => $destination['upi_id'] ?? null,
                'account_number' => $destination['account_number'] ?? null,
                'ifsc_code' => $destination['ifsc_code'] ?? null,
                'account_name' => $destination['account_name'] ?? null,
                'status' => CommissionPayout::STATUS_PENDING,
            ]);

            // The hold. Negative and already `available`, so it comes straight
            // off the withdrawable balance the moment the request is made.
            CommissionLedger::post($agent, [
                'type' => CommissionEntry::TYPE_PAYOUT,
                'status' => CommissionEntry::STATUS_AVAILABLE,
                'amount' => -round($amount, 2),
                'payout_id' => $payout->id,
                'available_at' => now(),
                'released_at' => now(),
                'note' => 'Payout requested.',
            ]);

            return $payout->refresh();
        });
    }

    /**
     * An admin pays an agent without the agent asking, and without the balance
     * having to cover it — an advance against future commission.
     *
     * Deliberately skips the minimum-payout floor and the available-balance
     * check that `requestPayout` enforces. Overpaying is the point: the ledger
     * takes the full negative row, so the account simply goes below zero and the
     * next accruals pay the advance back before the agent can withdraw again.
     *
     * Settles immediately (`approved` + a `paid` ledger row) because the money
     * has already left the admin's hand — there is nothing left to approve.
     */
    public function payAdvance(
        User $agent,
        float $amount,
        string $method,
        array $destination,
        ?int $adminId,
        ?string $notes = null,
    ): CommissionPayout {
        if (!$agent->isAgent()) {
            throw new RuntimeException('This user is not a referral agent.');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('Advance amount must be greater than zero.');
        }

        $payout = DB::transaction(function () use ($agent, $amount, $method, $destination, $adminId, $notes) {
            $payout = CommissionPayout::create([
                'agent_id' => $agent->id,
                'branch_id' => $agent->branch_id,
                'amount' => $amount,
                'method' => $method,
                'upi_id' => $destination['upi_id'] ?? null,
                'account_number' => $destination['account_number'] ?? null,
                'ifsc_code' => $destination['ifsc_code'] ?? null,
                'account_name' => $destination['account_name'] ?? null,
                'status' => CommissionPayout::STATUS_APPROVED,
                'notes' => $notes,
                'processed_by' => $adminId,
                'processed_at' => now(),
            ]);

            // Straight to `paid`: `recompute()` counts paid payout rows against
            // the available balance, so this is what pushes the account negative.
            CommissionLedger::post($agent, [
                'type' => CommissionEntry::TYPE_PAYOUT,
                'status' => CommissionEntry::STATUS_PAID,
                'amount' => -$amount,
                'payout_id' => $payout->id,
                'available_at' => now(),
                'released_at' => now(),
                'note' => filled($notes) ? 'Advance paid by admin. ' . $notes : 'Advance paid by admin.',
                'created_by' => $adminId,
            ]);

            return $payout->refresh();
        });

        ReferralAudit::record(
            branchId: (int) $agent->branch_id,
            userId: (int) $agent->id,
            action: ReferralAudit::ACTION_ADVANCE,
            actorId: $adminId,
            reason: $notes,
            meta: ['amount' => $amount, 'payout_id' => $payout->id, 'method' => $method],
        );

        try {
            UserPushNotifier::notifyCommissionPayout(
                (int) $agent->id,
                number_format($amount, 2),
                CommissionPayout::STATUS_APPROVED,
            );
        } catch (\Throwable $e) {
            Log::warning('Commission advance push failed.', ['error' => $e->getMessage()]);
        }

        return $payout;
    }

    /**
     * Approve or reject a payout. Approving marks the hold `paid`; rejecting
     * voids it, which hands the balance straight back.
     */
    public function processPayout(CommissionPayout $payout, string $status, ?int $adminId, ?string $notes = null): CommissionPayout
    {
        if ($payout->status !== CommissionPayout::STATUS_PENDING) {
            throw new RuntimeException('This payout has already been processed.');
        }

        DB::transaction(function () use ($payout, $status, $adminId, $notes) {
            $payout->status = $status;
            $payout->notes = $notes;
            $payout->processed_by = $adminId;
            $payout->processed_at = now();
            $payout->save();

            $hold = CommissionEntry::query()
                ->where('payout_id', $payout->id)
                ->where('type', CommissionEntry::TYPE_PAYOUT)
                ->first();

            if ($hold) {
                CommissionLedger::transition(
                    $hold,
                    $status === CommissionPayout::STATUS_APPROVED
                        ? CommissionEntry::STATUS_PAID
                        : CommissionEntry::STATUS_VOID,
                    ['note' => $status === CommissionPayout::STATUS_APPROVED
                        ? 'Payout paid.'
                        : 'Payout rejected — amount returned.'],
                );
            }
        });

        try {
            UserPushNotifier::notifyCommissionPayout(
                (int) $payout->agent_id,
                number_format((float) $payout->amount, 2),
                $status,
            );
        } catch (\Throwable $e) {
            Log::warning('Commission payout push failed.', ['error' => $e->getMessage()]);
        }

        return $payout->refresh();
    }
}
