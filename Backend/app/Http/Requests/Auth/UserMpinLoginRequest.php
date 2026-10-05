<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UserMpinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Players sign in with their phone number only. The old User ID
        // (unique_number) login is gone by owner decision — in the app AND here,
        // so the API cannot be used to sign in by User ID either.
        return [
            'phone' => ['required', 'string', 'max:32'],
            'branch_code' => ['nullable', 'string', 'max:40'],
            'mpin' => ['required', 'digits:6'],
        ];
    }
}
