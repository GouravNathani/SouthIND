<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('branches', 'code')],
            'domain' => ['nullable', 'string', 'max:191', Rule::unique('branches', 'domain')],
            'is_active' => ['sometimes', 'boolean'],
            'agent_enabled' => ['sometimes', 'boolean'],
            'min_deposit_amount' => ['nullable', 'integer', 'min:0'],
            'min_withdrawal_amount' => ['nullable', 'integer', 'min:0'],
            'copy_from_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ];
    }
}
