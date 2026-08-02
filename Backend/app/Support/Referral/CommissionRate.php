<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\ReferralSetting;
use App\Models\User;

/**
 * Works out what percentage an agent earns right now, and why.
 *
 * Precedence, highest first:
 *   1. users.commission_percent_override  — a rate negotiated with one agent
 *   2. the tier ladder, if the branch runs tiers
 *   3. the branch's flat commission_percent
 *
 * Level 2 (sub-team) is always the branch's flat level2_percent — tiers and
 * per-agent overrides deliberately do not cascade upwards, otherwise a single
 * generous override would quietly inflate every ancestor's payout too.
 */
class CommissionRate
{
    /**
     * @return array{percent: float, label: ?string, source: string}
     */
    public static function resolve(User $agent, ReferralSetting $settings, int $level = 1): array
    {
        if ($level >= 2) {
            return [
                'percent' => (float) $settings->level2_percent,
                'label' => 'Level 2',
                'source' => 'level2',
            ];
        }

        if ($agent->commission_percent_override !== null) {
            return [
                'percent' => (float) $agent->commission_percent_override,
                'label' => 'Custom',
                'source' => 'override',
            ];
        }

        if ($settings->tiers_enabled) {
            $tier = self::tierFor($agent, $settings);

            if ($tier) {
                return [
                    'percent' => $tier['percent'],
                    'label' => $tier['label'],
                    'source' => 'tier',
                ];
            }
        }

        return [
            'percent' => (float) $settings->commission_percent,
            'label' => null,
            'source' => 'flat',
        ];
    }

    /**
     * The highest tier the agent's lifetime team volume has reached.
     *
     * @return array{label: string, min_volume: float, percent: float}|null
     */
    public static function tierFor(User $agent, ReferralSetting $settings): ?array
    {
        $ladder = $settings->tierLadder();

        if ($ladder === []) {
            return null;
        }

        $volume = (float) (CommissionAccount::query()
            ->where('user_id', $agent->id)
            ->value('team_deposit_total') ?? 0);

        $matched = null;

        // Sorted ascending by tierLadder(), so the last one that fits wins.
        foreach ($ladder as $tier) {
            if ($volume >= $tier['min_volume']) {
                $matched = $tier;
            }
        }

        return $matched;
    }

    /**
     * What the agent needs for the next rung, for the progress bar in the app.
     *
     * @return array{label: string, percent: float, min_volume: float, remaining: float}|null
     */
    public static function nextTier(User $agent, ReferralSetting $settings, float $volume): ?array
    {
        foreach ($settings->tierLadder() as $tier) {
            if ($volume < $tier['min_volume']) {
                return $tier + ['remaining' => round($tier['min_volume'] - $volume, 2)];
            }
        }

        return null;
    }
}
