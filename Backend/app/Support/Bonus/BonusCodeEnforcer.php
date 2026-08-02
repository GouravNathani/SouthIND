<?php

namespace App\Support\Bonus;

use App\Models\BonusCodeRedemption;
use App\Models\Deposit;

/**
 * Drives the lifecycle of a bonus code that was applied to a deposit: the
 * reward unlocks when that deposit is approved and is released back to the
 * user (code reusable) when it is not.
 */
class BonusCodeEnforcer
{
    public static function onDepositApproved(Deposit $deposit): void
    {
        $redemption = self::parkedRedemptionFor($deposit);

        if (!$redemption) {
            return;
        }

        $bonusCode = $redemption->bonusCode()->first();

        // The approved amount is what actually counts towards a minimum.
        $minDeposit = (float) ($bonusCode->min_deposit ?? 0);
        if ($minDeposit > 0 && (float) $deposit->amount < $minDeposit) {
            BonusCodeRedeemer::release($redemption, 'Approved deposit below the code minimum.');

            return;
        }

        if ($bonusCode && $bonusCode->auto_approve) {
            $redemption->status = BonusCodeRedemption::STATUS_FULFILLED;
            $redemption->fulfilled_at = now();
        } else {
            // Surfaces in the admin payout queue now that the deposit cleared.
            $redemption->status = BonusCodeRedemption::STATUS_PENDING;
        }

        $redemption->save();
    }

    /**
     * Deposit rejected / failed: give the user their code back.
     */
    public static function onDepositDeclined(Deposit $deposit): void
    {
        $redemption = self::parkedRedemptionFor($deposit);

        if (!$redemption) {
            return;
        }

        BonusCodeRedeemer::release($redemption, 'Deposit was not approved.');
    }

    protected static function parkedRedemptionFor(Deposit $deposit): ?BonusCodeRedemption
    {
        return BonusCodeRedemption::query()
            ->where('deposit_id', $deposit->id)
            ->where('status', BonusCodeRedemption::STATUS_AWAITING_DEPOSIT)
            ->first();
    }
}
