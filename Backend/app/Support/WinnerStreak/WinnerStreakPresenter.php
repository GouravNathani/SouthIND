<?php

namespace App\Support\WinnerStreak;

use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Support\PhoneMask;

/**
 * Decides what a viewer is allowed to see.
 *
 * Two audiences, two rules:
 *  - Admin / Super admin: everything, exact rupees, real names.
 *  - End users: only the fields the branch admin ticked, names masked and
 *    amounts rounded down to a bucket ("₹50K+") by default.
 *
 * The bucketing is deliberate. Publishing "Rahul Kumar won ₹52,340" tells every
 * other user — and every scraper — exactly how much money moves through the
 * panel, and paints a target on the winner. A bucket keeps the bragging value
 * without the leak.
 */
class WinnerStreakPresenter
{
    /**
     * Full-fidelity row for the admin / super-admin panels.
     *
     * @return array<string, mixed>
     */
    public static function forAdmin(WinnerStreakEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'cycle_id' => $entry->cycle_id,
            'user_id' => $entry->user_id,
            'kind' => $entry->kind,
            'rank' => $entry->rank,
            'name' => $entry->display_name,
            'play_id' => $entry->display_play_id,
            'phone' => PhoneMask::apply($entry->display_phone),
            'deposit_total' => (float) $entry->deposit_total,
            'withdrawal_total' => (float) $entry->withdrawal_total,
            'bonus_total' => (float) $entry->bonus_total,
            'net_amount' => (float) $entry->net_amount,
            'transactions_count' => (int) $entry->transactions_count,
            'reward_amount' => (float) $entry->reward_amount,
            'reward_label' => $entry->reward_label,
            'reward_status' => $entry->reward_status,
            'paid_at' => $entry->paid_at?->toIso8601String(),
            'notes' => $entry->notes,
        ];
    }

    /**
     * Row as an end user is allowed to see it.
     *
     * @return array<string, mixed>
     */
    public static function forUser(WinnerStreakEntry $entry, WinnerStreakSetting $settings): array
    {
        $row = [
            'rank' => (int) $entry->rank,
            'kind' => $entry->kind,
        ];

        if ($settings->show_name) {
            $row['name'] = $settings->mask_name
                ? self::maskName($entry->display_name)
                : $entry->display_name;
        }

        if ($settings->show_play_id) {
            $row['play_id'] = $entry->display_play_id;
        }

        if ($settings->show_phone) {
            // Never the full number on the public side, whatever the flag says.
            $row['phone'] = $entry->display_phone
                ? PhoneMask::mask($entry->display_phone)
                : null;
        }

        if ($settings->show_amount) {
            $row['amount'] = self::publicAmount((float) $entry->net_amount, $settings);
        }

        if ($settings->show_profit_loss) {
            $row['result'] = ((float) $entry->net_amount) >= 0 ? 'profit' : 'loss';
        }

        if ($settings->show_reward && (float) $entry->reward_amount > 0) {
            // Exact, never bucketed: the reward is a figure the admin
            // advertises, and "₹500+" for a flat ₹500 prize reads as a bigger
            // promise than the panel intends to keep.
            $row['reward'] = '₹' . number_format((float) $entry->reward_amount, 0);
            $row['reward_label'] = $entry->reward_label;
        }

        return $row;
    }

    /**
     * The short label shown in the ribbon at the top of the user dashboard.
     */
    public static function publicName(?string $name, ?string $playId, WinnerStreakSetting $settings): string
    {
        if ($settings->show_name && $name) {
            return $settings->mask_name ? self::maskName($name) : $name;
        }

        if ($settings->show_play_id && $playId) {
            return $playId;
        }

        return $playId ?: 'A player';
    }

    /**
     * Exact rupees for admins, a rounded-down bucket for everyone else.
     */
    public static function publicAmount(float $amount, WinnerStreakSetting $settings): string
    {
        if (!$settings->mask_amount_bucket) {
            return '₹' . number_format(abs($amount), 0);
        }

        return self::bucket($amount);
    }

    /**
     * Round DOWN to a friendly bucket: 52,340 -> "₹50K+", 3,40,000 -> "₹3L+".
     * Rounding down matters — a bucket must never overstate a win.
     */
    public static function bucket(float $amount): string
    {
        $abs = abs($amount);

        if ($abs >= 10000000) {
            return '₹' . (int) floor($abs / 10000000) . 'Cr+';
        }

        if ($abs >= 100000) {
            return '₹' . (int) floor($abs / 100000) . 'L+';
        }

        if ($abs >= 10000) {
            return '₹' . ((int) floor($abs / 10000)) * 10 . 'K+';
        }

        if ($abs >= 1000) {
            return '₹' . (int) floor($abs / 1000) . 'K+';
        }

        return '₹' . ((int) floor($abs / 100)) * 100 . '+';
    }

    /**
     * "Rahul Kumar" -> "Rah**** K."
     */
    public static function maskName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 'Player';
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts);

        $keep = mb_substr($first, 0, min(3, mb_strlen($first)));
        $stars = max(2, mb_strlen($first) - mb_strlen($keep));
        $masked = $keep . str_repeat('*', $stars);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $masked .= ' ' . mb_strtoupper(mb_substr($part, 0, 1)) . '.';
        }

        return $masked;
    }
}
