<?php

namespace App\Support\Referral;

use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Withdrawal;

/**
 * The checks that stop a referral programme from becoming a payout leak.
 *
 * Each method returns NULL when the link is allowed, or a human-readable reason
 * when it is not — the reason goes straight into the admin's error toast and
 * into the audit trail, so it is written for a person, not for a log grep.
 */
class ReferralGuard
{
    /**
     * Can `$referrer` be recorded as the agent for `$user`?
     */
    public static function checkAttach(User $user, User $referrer, ReferralSetting $settings): ?string
    {
        if ($user->id === $referrer->id) {
            return 'A user cannot refer themselves.';
        }

        // Referral is branch-local. Two users of different branches never link.
        if ((int) $user->branch_id !== (int) $referrer->branch_id) {
            return 'Referrer must belong to the same branch as the user.';
        }

        if ($referrer->status === User::STATUS_BANNED) {
            return 'That referrer is banned.';
        }

        if (self::wouldCycle($user, $referrer)) {
            return 'That would create a referral loop.';
        }

        if ($settings->block_same_phone && self::samePhone($user, $referrer)) {
            return 'Referrer and user share the same phone number.';
        }

        if ($settings->block_shared_payout && self::sharedPayoutDestination($user, $referrer)) {
            return 'Referrer and user have withdrawn to the same account.';
        }

        if ($settings->max_referrals_per_day > 0) {
            $today = User::query()
                ->where('referred_by', $referrer->id)
                ->whereDate('referred_at', now()->toDateString())
                ->count();

            if ($today >= $settings->max_referrals_per_day) {
                return "That agent has reached today's limit of {$settings->max_referrals_per_day} referrals.";
            }
        }

        return null;
    }

    /**
     * Walk up from the proposed referrer: if we reach the user, linking them
     * would make the chain circular and every upward traversal would hang.
     */
    public static function wouldCycle(User $user, User $referrer): bool
    {
        $seen = [];
        $cursor = $referrer;

        // Depth-capped as a second line of defence against pre-existing loops.
        for ($i = 0; $i < 20 && $cursor; $i++) {
            if ((int) $cursor->id === (int) $user->id) {
                return true;
            }

            if (isset($seen[$cursor->id])) {
                return true;
            }

            $seen[$cursor->id] = true;

            if (!$cursor->referred_by) {
                return false;
            }

            $cursor = User::query()->find($cursor->referred_by);
        }

        return false;
    }

    public static function samePhone(User $a, User $b): bool
    {
        $normalize = static fn (?string $phone): string => preg_replace('/\D+/', '', (string) $phone) ?: '';

        $left = $normalize($a->phone);
        $right = $normalize($b->phone);

        if ($left === '' || $right === '') {
            return false;
        }

        // Compare the last 10 digits so +91 / 0 prefixes do not hide a match.
        return substr($left, -10) === substr($right, -10);
    }

    /**
     * Have these two accounts ever withdrawn to the same UPI id or bank account?
     * The single strongest signal that "two users" are one person.
     */
    public static function sharedPayoutDestination(User $a, User $b): bool
    {
        $destinations = static function (int $userId): array {
            $rows = Withdrawal::query()
                ->where('user_id', $userId)
                ->get(['upi_id', 'account_number']);

            $values = [];

            foreach ($rows as $row) {
                if (filled($row->upi_id)) {
                    $values[] = 'upi:' . strtolower(trim($row->upi_id));
                }
                if (filled($row->account_number)) {
                    $values[] = 'acc:' . preg_replace('/\s+/', '', $row->account_number);
                }
            }

            return array_unique($values);
        };

        $left = $destinations($a->id);

        if ($left === []) {
            return false;
        }

        return array_intersect($left, $destinations($b->id)) !== [];
    }
}
