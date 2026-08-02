<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch configuration of the referral / agent commission programme.
 */
class ReferralSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'enabled',
        'commission_percent',
        'tiers_enabled',
        'tiers',
        'level2_enabled',
        'level2_percent',
        'min_deposit_amount',
        'first_deposit_only',
        'holding_hours',
        'monthly_cap_per_agent',
        'per_deposit_cap',
        'max_referrals_per_day',
        'block_same_phone',
        'block_shared_payout',
        'washout_hours',
        'auto_promote_to_agent',
        'allow_self_signup_code',
        'retroactive_on_attach',
        'min_payout_amount',
        'payout_to_bank_enabled',
        'payout_to_play_enabled',
        'notify_on_commission',
        'notify_on_join',
        'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'enabled' => 'boolean',
        'commission_percent' => 'decimal:3',
        'tiers_enabled' => 'boolean',
        'tiers' => 'array',
        'level2_enabled' => 'boolean',
        'level2_percent' => 'decimal:3',
        'min_deposit_amount' => 'decimal:2',
        'first_deposit_only' => 'boolean',
        'holding_hours' => 'integer',
        'monthly_cap_per_agent' => 'decimal:2',
        'per_deposit_cap' => 'decimal:2',
        'max_referrals_per_day' => 'integer',
        'block_same_phone' => 'boolean',
        'block_shared_payout' => 'boolean',
        'washout_hours' => 'integer',
        'auto_promote_to_agent' => 'boolean',
        'allow_self_signup_code' => 'boolean',
        'retroactive_on_attach' => 'boolean',
        'min_payout_amount' => 'decimal:2',
        'payout_to_bank_enabled' => 'boolean',
        'payout_to_play_enabled' => 'boolean',
        'notify_on_commission' => 'boolean',
        'notify_on_join' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Config for a branch, created with the migration defaults on first touch.
     */
    public static function forBranch(int $branchId): self
    {
        $settings = static::query()->firstOrCreate(['branch_id' => $branchId], []);

        // A freshly inserted model carries only the attributes we passed in —
        // without this re-read every default column reads back as null.
        if ($settings->wasRecentlyCreated) {
            $settings->refresh();
        }

        return $settings;
    }

    /**
     * Tier ladder as a clean, sorted list. Malformed rows are dropped rather
     * than allowed to poison a commission calculation.
     *
     * @return array<int, array{label: string, min_volume: float, percent: float}>
     */
    public function tierLadder(): array
    {
        $tiers = [];

        foreach ((array) ($this->tiers ?? []) as $tier) {
            if (!is_array($tier) || !isset($tier['percent'])) {
                continue;
            }

            $tiers[] = [
                'label' => (string) ($tier['label'] ?? 'Tier'),
                'min_volume' => (float) ($tier['min_volume'] ?? 0),
                'percent' => (float) $tier['percent'],
            ];
        }

        usort($tiers, fn ($a, $b) => $a['min_volume'] <=> $b['min_volume']);

        return $tiers;
    }
}
