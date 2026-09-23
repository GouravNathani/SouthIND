<?php

namespace App\Http\Requests\User;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WithdrawalRequest extends FormRequest
{
    private const DEFAULT_MINIMUM_AMOUNT = 500;
    private const MAXIMUM_AMOUNT = 9999999999.99;
    private const IFSC_REGEX = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $ifsc = $this->input('ifsc_code');
        if (is_string($ifsc)) {
            $this->merge(['ifsc_code' => strtoupper(trim($ifsc))]);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'min:' . $this->resolveMinimumAmount(),
                'max:' . self::MAXIMUM_AMOUNT,
            ],
            'destination_type' => ['required', Rule::in(['upi', 'bank'])],
            'upi_id' => [
                'nullable',
                'string',
                'max:160',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'upi'),
            ],
            'account_number' => [
                'nullable',
                'string',
                'max:60',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'bank'),
            ],
            'ifsc_code' => [
                'nullable',
                'string',
                'size:11',
                'regex:' . self::IFSC_REGEX,
                Rule::requiredIf(fn () => $this->input('destination_type') === 'bank'),
            ],
            'account_name' => [
                'nullable',
                'string',
                'max:120',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'bank'),
            ],
            'play_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.decimal' => 'Amount may contain up to 2 decimal places only.',
            'amount.max' => 'Amount exceeds the maximum allowed limit.',
        ];
    }

    private function resolveMinimumAmount(): int
    {
        $branchId = $this->user()?->branch_id;
        if (!$branchId) {
            return self::DEFAULT_MINIMUM_AMOUNT;
        }

        $minAmount = Branch::query()->whereKey($branchId)->value('min_withdrawal_amount');
        if ($minAmount !== null && is_numeric($minAmount)) {
            return (int) $minAmount;
        }

        return self::DEFAULT_MINIMUM_AMOUNT;
    }
}
