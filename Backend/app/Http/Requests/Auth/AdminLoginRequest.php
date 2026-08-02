<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Allow login via phone or email so seeded .env credentials work either way
            'phone' => ['sometimes', 'string', 'required_without:email'],
            'email' => ['sometimes', 'string', 'email', 'required_without:phone'],
            'password' => ['required', 'string'],
        ];
    }
}
