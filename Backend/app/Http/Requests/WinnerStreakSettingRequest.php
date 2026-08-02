<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the Admin and Super Admin panels — the settings shape is identical,
 * only the branch the row belongs to is resolved differently.
 */
class WinnerStreakSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],

            'tag_id' => ['sometimes', 'nullable', 'integer'],
            'exclude_tag_id' => ['sometimes', 'nullable', 'integer'],

            'top_n' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'loss_board_enabled' => ['sometimes', 'boolean'],
            'loss_board_public' => ['sometimes', 'boolean'],

            'min_turnover' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'min_transactions' => ['sometimes', 'integer', 'min:0', 'max:1000'],

            'reset_time' => ['sometimes', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'reset_weekday' => ['sometimes', 'integer', 'min:1', 'max:7'],
            // Capped at 28 so the boundary exists in February too.
            'reset_day_of_month' => ['sometimes', 'integer', 'min:1', 'max:28'],

            'show_name' => ['sometimes', 'boolean'],
            'show_play_id' => ['sometimes', 'boolean'],
            'show_phone' => ['sometimes', 'boolean'],
            'show_amount' => ['sometimes', 'boolean'],
            'show_profit_loss' => ['sometimes', 'boolean'],
            'show_reward' => ['sometimes', 'boolean'],
            'mask_amount_bucket' => ['sometimes', 'boolean'],
            'mask_name' => ['sometimes', 'boolean'],

            'reward_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'reward_label' => ['sometimes', 'nullable', 'string', 'max:40'],

            'announce_push' => ['sometimes', 'boolean'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_from' => ['sometimes', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'quiet_to' => ['sometimes', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],

            // 0 = off. Capped low: a long cooldown on a small branch empties
            // the board entirely.
            'winner_cooldown_cycles' => ['sometimes', 'integer', 'min:0', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_time.regex' => 'Reset time must be HH:MM (24-hour).',
            'quiet_from.regex' => 'Quiet hours start must be HH:MM (24-hour).',
            'quiet_to.regex' => 'Quiet hours end must be HH:MM (24-hour).',
        ];
    }
}
