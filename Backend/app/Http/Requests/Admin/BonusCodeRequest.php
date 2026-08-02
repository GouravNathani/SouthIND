<?php

namespace App\Http\Requests\Admin;

use App\Models\BonusCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BonusCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Codes are matched case-insensitively, so normalise before the unique rule
     * runs — otherwise "welcome100" would slip past an existing "WELCOME100".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => BonusCode::normalise($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $bonusCode = $this->route('bonus_code');
        $isUpdate = $bonusCode !== null;

        return [
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:40',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('bonus_codes', 'code')->ignore($bonusCode?->id),
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'terms_text' => ['nullable', 'string', 'max:2000'],

            'reward_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reward_label' => ['nullable', 'string', 'max:40'],

            'frequency' => ['required', Rule::in(BonusCode::FREQUENCIES)],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],

            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['nullable', Rule::in([BonusCode::STATUS_ACTIVE, BonusCode::STATUS_PAUSED])],

            'auto_approve' => ['sometimes', 'boolean'],
            'requires_deposit' => ['sometimes', 'boolean'],
            'min_deposit' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'new_user_days' => ['nullable', 'integer', 'min:1', 'max:3650'],

            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'A code may only contain letters, numbers, dot, dash and underscore.',
        ];
    }
}
