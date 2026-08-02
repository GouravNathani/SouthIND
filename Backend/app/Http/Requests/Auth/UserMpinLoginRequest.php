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
        return [
            'user_id' => ['nullable', 'string', 'max:32', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:user_id'],
            'branch_code' => ['nullable', 'string', 'max:40'],
            'mpin' => ['required', 'digits:6'],
        ];
    }
}
