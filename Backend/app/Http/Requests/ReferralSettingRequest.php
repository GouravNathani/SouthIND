<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the Admin and Super Admin panels — the settings shape is identical,
 * only the branch the row belongs to is resolved differently.
 */
class ReferralSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],

            // A percentage, not a fraction: 2.5 means 2.5%. Capped at 100 so a
            // fat-fingered "250" cannot pay more than the deposit itself.
            'commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],

            'tiers_enabled' => ['sometimes', 'boolean'],
            'tiers' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tiers.*.label' => ['required_with:tiers', 'string', 'max:40'],
            'tiers.*.min_volume' => ['required_with:tiers', 'numeric', 'min:0', 'max:999999999'],
            'tiers.*.percent' => ['required_with:tiers', 'numeric', 'min:0', 'max:100'],

            'level2_enabled' => ['sometimes', 'boolean'],
            'level2_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],

            'min_deposit_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'first_deposit_only' => ['sometimes', 'boolean'],
            // Up to 30 days of holding.
            'holding_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'monthly_cap_per_agent' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'per_deposit_cap' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],

            'max_referrals_per_day' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'block_same_phone' => ['sometimes', 'boolean'],
            'block_shared_payout' => ['sometimes', 'boolean'],
            'washout_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],

            'auto_promote_to_agent' => ['sometimes', 'boolean'],
            'allow_self_signup_code' => ['sometimes', 'boolean'],
            'retroactive_on_attach' => ['sometimes', 'boolean'],

            'min_payout_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'payout_to_bank_enabled' => ['sometimes', 'boolean'],
            'payout_to_play_enabled' => ['sometimes', 'boolean'],

            'notify_on_commission' => ['sometimes', 'boolean'],
            'notify_on_join' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'commission_percent.max' => 'Commission percent cannot exceed 100%.',
            'tiers.max' => 'At most 10 tiers can be configured.',
        ];
    }
}
