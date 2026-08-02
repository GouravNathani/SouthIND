<?php

namespace App\Support\Referral;

use App\Models\User;

/**
 * Referral code minting.
 *
 * A referral code is NOT the user's play_id or unique_number: those identify an
 * account and get shared in support threads, whereas a referral code is meant to
 * be posted publicly. Keeping them separate means a code can be rotated after a
 * leak without touching the account's identity.
 */
class ReferralCode
{
    /** Same confusable-free alphabet as bonus codes — read aloud over a call. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    /**
     * A code that is free across the whole system. Codes are globally unique
     * even though referral itself is branch-scoped — two branches sharing a
     * code string would make a mistyped code silently resolve to a stranger.
     */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * The agent's code, minted on first use.
     */
    public static function ensureFor(User $user): string
    {
        if (filled($user->referral_code)) {
            return $user->referral_code;
        }

        $user->referral_code = self::generate();
        $user->save();

        return $user->referral_code;
    }

    /**
     * Resolve a typed code to an agent WITHIN A BRANCH.
     *
     * The branch filter is the whole point: a user can only ever be referred by
     * an agent of their own branch, so a valid code from another branch must
     * behave exactly like an invalid one.
     */
    public static function resolve(?string $code, int $branchId): ?User
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return User::query()
            ->where('branch_id', $branchId)
            ->where('referral_code', $code)
            ->first();
    }

    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }
}
