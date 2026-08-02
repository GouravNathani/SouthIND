<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('branches', 'code')->ignore($branchId)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:191', Rule::unique('branches', 'domain')->ignore($branchId)],
            'is_active' => ['sometimes', 'boolean'],
            'agent_enabled' => ['sometimes', 'boolean'],
            'min_deposit_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_withdrawal_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
