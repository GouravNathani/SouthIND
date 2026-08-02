<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:12'],
            'role' => ['sometimes', 'string', Rule::in(['admin'])],
            'is_active' => ['sometimes', 'boolean'],
            'allow_profit_view' => ['sometimes', 'boolean'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ];
    }
}
