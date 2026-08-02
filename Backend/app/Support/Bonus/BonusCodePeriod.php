<?php

namespace App\Support\Bonus;

use App\Models\BonusCode;
use Illuminate\Support\Carbon;

/**
 * Turns a code's frequency into the period bucket a redemption belongs to.
 *
 * The bucket string is stored on the redemption and covered by a unique index
 * (bonus_code_id, user_id, period_key), so the "once a day / once a month"
 * rule is enforced by the database rather than by application checks alone.
 */
class BonusCodePeriod
{
    public static function keyFor(BonusCode $code, ?Carbon $at = null): string
    {
        $at = $at ?? now();

        return match ($code->frequency) {
            BonusCode::FREQ_DAILY => $at->format('Y-m-d'),
            BonusCode::FREQ_WEEKLY => $at->format('o-\WW'),
            BonusCode::FREQ_MONTHLY => $at->format('Y-m'),
            // "unlimited" still needs distinct keys per attempt; the slot suffix
            // added by the redeemer supplies them.
            BonusCode::FREQ_UNLIMITED => $at->format('Y-m-d\TH:i:s.u'),
            default => 'life',
        };
    }

    /**
     * Slot 1 keeps the bare key so the common (one-per-period) case stays
     * readable in the database; later slots get a suffix.
     */
    public static function withSlot(string $key, int $slot): string
    {
        return $slot <= 1 ? $key : $key . '#' . $slot;
    }

    /**
     * Human label for the frequency, used in user-facing error messages.
     */
    public static function label(string $frequency): string
    {
        return match ($frequency) {
            BonusCode::FREQ_DAILY => 'today',
            BonusCode::FREQ_WEEKLY => 'this week',
            BonusCode::FREQ_MONTHLY => 'this month',
            BonusCode::FREQ_UNLIMITED => 'right now',
            default => 'already',
        };
    }
}
