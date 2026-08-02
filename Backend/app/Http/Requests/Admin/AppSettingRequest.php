<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deposit_offer_text' => ['nullable', 'string', 'max:255'],
            'withdrawal_offer_text' => ['nullable', 'string', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:64'],
            'deposit_wa' => ['nullable', 'string', 'max:64'],
            'withdrawal_wa' => ['nullable', 'string', 'max:64'],
            'whatsapp_link' => ['nullable', 'string', 'max:2048'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'support_auto_reply_enabled' => ['sometimes', 'boolean'],
            'support_auto_reply_text' => ['nullable', 'string', 'max:4000'],
            'bonus_deposit_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
