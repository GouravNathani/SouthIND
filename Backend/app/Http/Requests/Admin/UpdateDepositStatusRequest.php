<?php

namespace App\Http\Requests\Admin;

use App\Models\Deposit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepositStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                Deposit::STATUS_ON_PROCESS,
                Deposit::STATUS_APPROVED,
                Deposit::STATUS_REJECTED,
                Deposit::STATUS_FAILED,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
