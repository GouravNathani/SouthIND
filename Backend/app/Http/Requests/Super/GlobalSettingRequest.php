<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

class GlobalSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instagram_link' => ['nullable', 'string', 'max:2048'],
            'telegram_link' => ['nullable', 'string', 'max:2048'],
            'whatsapp_link' => ['nullable', 'string', 'max:2048'],
            'mask_user_phone' => ['sometimes', 'boolean'],
            'user_panel_maintenance_enabled' => ['sometimes', 'boolean'],
            'bonus_deposit_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
