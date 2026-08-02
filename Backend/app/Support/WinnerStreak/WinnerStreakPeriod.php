<?php

namespace App\Support\WinnerStreak;

use App\Models\WinnerStreakSetting;
use Illuminate\Support\Carbon;

/**
 * Turns a period + admin-chosen reset schedule into concrete window
 * boundaries.
 *
 * "Auto reset" is expressed as: the boundary at or before now opens the live
 * cycle, and the next boundary closes it. Because both are computed from the
 * schedule (never from "now + 24h"), a missed run — server down, cron paused —
 * self-heals: the tick simply closes however many windows have gone by.
 *
 * All arithmetic happens in the app timezone (Asia/Kolkata).
 */
class WinnerStreakPeriod
{
    /**
     * Most recent reset boundary at or before $at.
     */
    public static function boundaryBefore(Carbon $at, WinnerStreakSetting $settings): Carbon
    {
        $at = $at->copy()->setTimezone(config('app.timezone'));
        [$hour, $minute] = self::resetTimeParts($settings);

        return match ($settings->period) {
            WinnerStreakSetting::PERIOD_WEEKLY => self::weeklyBoundary($at, $settings, $hour, $minute),
            WinnerStreakSetting::PERIOD_MONTHLY => self::monthlyBoundary($at, $settings, $hour, $minute),
            default => self::dailyBoundary($at, $hour, $minute),
        };
    }

    /**
     * The boundary that follows $boundary — i.e. when the cycle starting at
     * $boundary is due to close.
     */
    public static function boundaryAfter(Carbon $boundary, WinnerStreakSetting $settings): Carbon
    {
        $next = $boundary->copy();

        return match ($settings->period) {
            WinnerStreakSetting::PERIOD_WEEKLY => $next->addWeek(),
            WinnerStreakSetting::PERIOD_MONTHLY => $next->addMonthNoOverflow(),
            default => $next->addDay(),
        };
    }

    /**
     * The first scheduled boundary strictly after $at.
     *
     * A cycle can START off-schedule — a manual "Reset Now" at 3pm, or a
     * catch-up after the cron was paused — but it must always END on a real
     * boundary, otherwise every later cycle inherits the drift and a board set
     * to reset at 21:00 slowly wanders to 03:00.
     */
    public static function nextBoundaryAfter(Carbon $at, WinnerStreakSetting $settings): Carbon
    {
        $boundary = self::boundaryAfter(self::boundaryBefore($at, $settings), $settings);

        // A single step is enough for a normal schedule; the loop is a guard
        // against a boundary landing exactly on $at.
        $guard = 0;
        while ($boundary->lte($at) && $guard++ < 8) {
            $boundary = self::boundaryAfter($boundary, $settings);
        }

        return $boundary;
    }

    /**
     * Human label for a window, used in the UI and in push copy.
     */
    public static function label(Carbon $startsAt, string $period): string
    {
        return match ($period) {
            WinnerStreakSetting::PERIOD_WEEKLY => $startsAt->format('o-\WW'),
            WinnerStreakSetting::PERIOD_MONTHLY => $startsAt->format('Y-m'),
            default => $startsAt->format('Y-m-d'),
        };
    }

    public static function periodLabel(string $period): string
    {
        return match ($period) {
            WinnerStreakSetting::PERIOD_WEEKLY => 'Weekly',
            WinnerStreakSetting::PERIOD_MONTHLY => 'Monthly',
            default => 'Daily',
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected static function resetTimeParts(WinnerStreakSetting $settings): array
    {
        $raw = (string) ($settings->reset_time ?: '00:00');

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return [0, 0];
        }

        return [min(23, (int) $m[1]), min(59, (int) $m[2])];
    }

    protected static function dailyBoundary(Carbon $at, int $hour, int $minute): Carbon
    {
        $boundary = $at->copy()->setTime($hour, $minute, 0);

        if ($boundary->gt($at)) {
            $boundary->subDay();
        }

        return $boundary;
    }

    protected static function weeklyBoundary(Carbon $at, WinnerStreakSetting $settings, int $hour, int $minute): Carbon
    {
        $weekday = (int) $settings->reset_weekday;
        $weekday = ($weekday >= 1 && $weekday <= 7) ? $weekday : 1;

        $boundary = $at->copy()->setTime($hour, $minute, 0);
        $delta = ($boundary->dayOfWeekIso - $weekday + 7) % 7;
        $boundary->subDays($delta);

        if ($boundary->gt($at)) {
            $boundary->subWeek();
        }

        return $boundary;
    }

    protected static function monthlyBoundary(Carbon $at, WinnerStreakSetting $settings, int $hour, int $minute): Carbon
    {
        // Capped at 28 so every month has the day — no February surprises.
        $day = min(28, max(1, (int) $settings->reset_day_of_month));

        $boundary = $at->copy()->startOfMonth()->addDays($day - 1)->setTime($hour, $minute, 0);

        if ($boundary->gt($at)) {
            $boundary = $at->copy()
                ->startOfMonth()
                ->subMonthNoOverflow()
                ->startOfMonth()
                ->addDays($day - 1)
                ->setTime($hour, $minute, 0);
        }

        return $boundary;
    }
}
