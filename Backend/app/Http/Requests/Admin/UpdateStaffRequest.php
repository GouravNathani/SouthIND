<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $staffId = $this->route('staff')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:32', Rule::unique('admins', 'phone')->ignore($staffId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($staffId)],
            'password' => ['nullable', 'string', 'min:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
