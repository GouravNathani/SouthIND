<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\ReferralAudit;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\PhoneMask;

/**
 * JSON shapes for the three panels.
 *
 * Admin and user views are separate methods on purpose: the user view never
 * exposes another member's phone number, the deposit id behind a commission, or
 * an admin's flag notes.
 */
class ReferralPresenter
{
    public static function settings(ReferralSetting $settings): array
    {
        return [
            'branch_id' => (int) $settings->branch_id,
            'enabled' => (bool) $settings->enabled,
            'commission_percent' => (float) $settings->commission_percent,
            'tiers_enabled' => (bool) $settings->tiers_enabled,
            'tiers' => $settings->tierLadder(),
            'level2_enabled' => (bool) $settings->level2_enabled,
            'level2_percent' => (float) $settings->level2_percent,
            'min_deposit_amount' => (float) $settings->min_deposit_amount,
            'first_deposit_only' => (bool) $settings->first_deposit_only,
            'holding_hours' => (int) $settings->holding_hours,
            'monthly_cap_per_agent' => (float) $settings->monthly_cap_per_agent,
            'per_deposit_cap' => (float) $settings->per_deposit_cap,
            'max_referrals_per_day' => (int) $settings->max_referrals_per_day,
            'block_same_phone' => (bool) $settings->block_same_phone,
            'block_shared_payout' => (bool) $settings->block_shared_payout,
            'washout_hours' => (int) $settings->washout_hours,
            'auto_promote_to_agent' => (bool) $settings->auto_promote_to_agent,
            'allow_self_signup_code' => (bool) $settings->allow_self_signup_code,
            'retroactive_on_attach' => (bool) $settings->retroactive_on_attach,
            'min_payout_amount' => (float) $settings->min_payout_amount,
            'payout_to_bank_enabled' => (bool) $settings->payout_to_bank_enabled,
            'payout_to_play_enabled' => (bool) $settings->payout_to_play_enabled,
            'notify_on_commission' => (bool) $settings->notify_on_commission,
            'notify_on_join' => (bool) $settings->notify_on_join,
            'updated_at' => optional($settings->updated_at)->toIso8601String(),
        ];
    }

    /** One row of the admin's agent list. */
    public static function agent(User $agent, ?CommissionAccount $account = null): array
    {
        $account ??= $agent->relationLoaded('commissionAccount')
            ? $agent->commissionAccount
            : CommissionAccount::query()->where('user_id', $agent->id)->first();

        return [
            'id' => (int) $agent->id,
            'name' => $agent->name,
            'phone' => PhoneMask::apply($agent->phone),
            'play_id' => $agent->play_id ?: $agent->unique_number,
            'unique_number' => $agent->unique_number,
            'branch_id' => (int) $agent->branch_id,
            'user_type' => $agent->user_type,
            'agent_status' => $agent->agent_status,
            'status' => $agent->status,
            'referral_code' => $agent->referral_code,
            'commission_percent_override' => $agent->commission_percent_override !== null
                ? (float) $agent->commission_percent_override
                : null,
            'referred_by' => $agent->referred_by ? (int) $agent->referred_by : null,
            'referrer_name' => $agent->relationLoaded('referrer') ? optional($agent->referrer)->name : null,
            'account' => $account ? self::account($account) : null,
            'created_at' => optional($agent->created_at)->toIso8601String(),
            'last_seen_at' => optional($agent->last_seen_at)->toIso8601String(),
        ];
    }

    public static function account(CommissionAccount $account): array
    {
        return [
            'available_balance' => (float) $account->available_balance,
            'pending_balance' => (float) $account->pending_balance,
            'lifetime_earned' => (float) $account->lifetime_earned,
            'lifetime_paid' => (float) $account->lifetime_paid,
            'lifetime_adjusted' => (float) $account->lifetime_adjusted,
            'team_count' => (int) $account->team_count,
            'team_active_count' => (int) $account->team_active_count,
            'team_deposit_total' => (float) $account->team_deposit_total,
            'tier_label' => $account->tier_label,
            'tier_percent' => $account->tier_percent !== null ? (float) $account->tier_percent : null,
            'status' => $account->status,
            'last_accrual_at' => optional($account->last_accrual_at)->toIso8601String(),
        ];
    }

    public static function entryForAdmin(CommissionEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'agent_id' => (int) $entry->agent_id,
            'agent_name' => $entry->relationLoaded('agent') ? optional($entry->agent)->name : null,
            'type' => $entry->type,
            'status' => $entry->status,
            'level' => (int) $entry->level,
            'from_user_id' => $entry->from_user_id ? (int) $entry->from_user_id : null,
            'from_name' => $entry->from_display_name,
            'from_play_id' => $entry->from_display_play_id,
            'deposit_id' => $entry->deposit_id ? (int) $entry->deposit_id : null,
            'payout_id' => $entry->payout_id ? (int) $entry->payout_id : null,
            'base_amount' => (float) $entry->base_amount,
            'percent' => (float) $entry->percent,
            'tier_label' => $entry->tier_label,
            'amount' => (float) $entry->amount,
            'available_at' => optional($entry->available_at)->toIso8601String(),
            'released_at' => optional($entry->released_at)->toIso8601String(),
            'deposit_status_at_accrual' => $entry->deposit_status_at_accrual,
            'deposit_status_now' => $entry->deposit_status_now,
            'flagged_at' => optional($entry->flagged_at)->toIso8601String(),
            'flag_reason' => $entry->flag_reason,
            'resolved_at' => optional($entry->resolved_at)->toIso8601String(),
            'note' => $entry->note,
            'created_at' => optional($entry->created_at)->toIso8601String(),
        ];
    }

    /**
     * The agent's own view of a ledger line. No deposit ids, no admin notes,
     * no flags — an agent seeing "flagged" would only cause a support ticket
     * the admin has not decided the answer to yet.
     */
    public static function entryForUser(CommissionEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'type' => $entry->type,
            'status' => $entry->status,
            'level' => (int) $entry->level,
            'from_name' => $entry->from_display_name,
            'from_play_id' => $entry->from_display_play_id,
            'base_amount' => (float) $entry->base_amount,
            'percent' => (float) $entry->percent,
            'amount' => (float) $entry->amount,
            'available_at' => optional($entry->available_at)->toIso8601String(),
            'created_at' => optional($entry->created_at)->toIso8601String(),
        ];
    }

    /** A team member as their own agent sees them. */
    public static function teamMemberForUser(User $member, float $depositTotal, float $earned): array
    {
        return [
            'id' => (int) $member->id,
            'name' => $member->name,
            // Agents get a masked number only — enough to recognise their own
            // recruit, not enough to hand the contact to a competitor.
            'phone' => self::maskTail($member->phone),
            'play_id' => $member->play_id ?: $member->unique_number,
            'joined_at' => optional($member->referred_at ?? $member->created_at)->toIso8601String(),
            'deposit_total' => $depositTotal,
            'commission_earned' => $earned,
            'is_active' => $depositTotal > 0,
        ];
    }

    public static function teamMemberForAdmin(User $member, float $depositTotal, float $earned): array
    {
        return [
            'id' => (int) $member->id,
            'name' => $member->name,
            'phone' => PhoneMask::apply($member->phone),
            'play_id' => $member->play_id ?: $member->unique_number,
            'status' => $member->status,
            'referral_source' => $member->referral_source,
            'joined_at' => optional($member->referred_at ?? $member->created_at)->toIso8601String(),
            'commission_from' => optional($member->commission_from)->toIso8601String(),
            'deposit_total' => $depositTotal,
            'commission_earned' => $earned,
        ];
    }

    public static function payout(CommissionPayout $payout, bool $forAdmin = true): array
    {
        $base = [
            'id' => (int) $payout->id,
            'agent_id' => (int) $payout->agent_id,
            'amount' => (float) $payout->amount,
            'method' => $payout->method,
            'status' => $payout->status,
            'created_at' => optional($payout->created_at)->toIso8601String(),
            'processed_at' => optional($payout->processed_at)->toIso8601String(),
            'notes' => $payout->notes,
        ];

        if (!$forAdmin) {
            return $base;
        }

        return $base + [
            'agent_name' => $payout->relationLoaded('agent') ? optional($payout->agent)->name : null,
            'upi_id' => $payout->upi_id,
            'account_number' => $payout->account_number,
            'ifsc_code' => $payout->ifsc_code,
            'account_name' => $payout->account_name,
            'processed_by' => $payout->processed_by ? (int) $payout->processed_by : null,
        ];
    }

    public static function audit(ReferralAudit $audit): array
    {
        return [
            'id' => (int) $audit->id,
            'user_id' => (int) $audit->user_id,
            'user_name' => $audit->relationLoaded('user') ? optional($audit->user)->name : null,
            'action' => $audit->action,
            'old_referrer_id' => $audit->old_referrer_id,
            'new_referrer_id' => $audit->new_referrer_id,
            'actor_type' => $audit->actor_type,
            'actor_id' => $audit->actor_id,
            'actor_name' => $audit->relationLoaded('actor') ? optional($audit->actor)->name : null,
            'reason' => $audit->reason,
            'meta' => $audit->meta,
            'created_at' => optional($audit->created_at)->toIso8601String(),
        ];
    }

    private static function maskTail(?string $phone): ?string
    {
        if (blank($phone)) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (strlen($digits) <= 4) {
            return str_repeat('•', strlen($digits));
        }

        return str_repeat('•', strlen($digits) - 4) . substr($digits, -4);
    }
}
