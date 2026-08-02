<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'unique:admins,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
