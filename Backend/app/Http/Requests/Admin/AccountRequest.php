<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $ifsc = $this->input('ifsc_code');
        if (is_string($ifsc)) {
            $this->merge(['ifsc_code' => trim($ifsc)]);
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');
        $requiredRule = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$requiredRule, 'string', 'max:120'],
            'type' => [$requiredRule, Rule::in(['upi', 'bank', 'qr'])],
            'upi_id' => [
                'nullable',
                'string',
                'max:160',
                Rule::requiredIf(fn () => $this->input('type') === 'upi'),
            ],
            'account_number' => [
                'nullable',
                'string',
                'max:60',
                Rule::requiredIf(fn () => $this->input('type') === 'bank'),
            ],
            'ifsc_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => $this->input('type') === 'bank'),
            ],
            'holder_name' => ['nullable', 'string', 'max:120'],
            'used_for' => [$requiredRule, Rule::in(['deposit', 'withdraw', 'both'])],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Cumulative approved-deposit cap; null/empty or 0 = no limit.
            'deposit_limit' => ['nullable', 'numeric', 'min:0'],
            // Per-deposit amount range; null/empty = no constraint.
            'min_deposit' => ['nullable', 'numeric', 'min:0'],
            'max_deposit' => ['nullable', 'numeric', 'min:0'],
            'scanner_image' => [
                'nullable',
                'image',
                'max:4096',
                Rule::requiredIf(fn () => $this->input('type') === 'qr' && !$isUpdate),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $min = $this->input('min_deposit');
            $max = $this->input('max_deposit');
            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add('max_deposit', 'Max deposit must be greater than or equal to min deposit.');
            }
        });
    }
}
