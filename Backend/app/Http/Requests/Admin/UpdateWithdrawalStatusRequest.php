<?php

namespace App\Http\Requests\Admin;

use App\Models\Withdrawal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWithdrawalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                Withdrawal::STATUS_ON_PROCESS,
                Withdrawal::STATUS_APPROVED,
                Withdrawal::STATUS_REJECTED,
                Withdrawal::STATUS_FAILED,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
