<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->user()?->resolveBranchId();
        $phoneRule = Rule::unique('users', 'phone');
        if ($branchId) {
            $phoneRule->where(static fn ($query) => $query->where('branch_id', $branchId));
        }
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', $phoneRule],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'play_id' => ['nullable', 'string', 'max:64'],
            // "Referral Agent" on the create form — the new user is attached to
            // this agent's team right after creation.
            'agent_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
