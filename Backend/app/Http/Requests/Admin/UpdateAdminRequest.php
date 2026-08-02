<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $adminId = $this->route('admin')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:32', Rule::unique('admins', 'phone')->ignore($adminId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($adminId)],
            'password' => ['nullable', 'string', 'min:12'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_profit_view' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')],
        ];
    }
}
