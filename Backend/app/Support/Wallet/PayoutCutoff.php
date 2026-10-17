<?php

namespace App\Support\Wallet;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * config('payout.start_at'): deposits approved before this moment are outside
 * billing. Applied to every approved-deposit query that feeds a payout, so the
 * monthly run and the Super Admin payout views always agree.
 */
class PayoutCutoff
{
    /**
     * The cut-off as a DB-timezone string, or null when not configured.
     */
    public static function startsAt(): ?string
    {
        $raw = trim((string) config('payout.start_at', ''));
        if ($raw === '') {
            return null;
        }

        $tz = config('app.timezone');
        $dbTz = config('database.connections.' . config('database.default') . '.timezone') ?: $tz;

        try {
            return Carbon::parse($raw, $tz)->setTimezone($dbTz)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            // A typo must not silently disable the guard: bill nothing instead.
            return '9999-12-31 00:00:00';
        }
    }

    public static function apply(Builder $query): Builder
    {
        $startsAt = self::startsAt();

        return $startsAt === null
            ? $query
            : $query->whereRaw('COALESCE(approved_at, created_at) >= ?', [$startsAt]);
    }
}
