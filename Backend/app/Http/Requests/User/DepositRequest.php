<?php

namespace App\Http\Requests\User;

use App\Models\Account;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    private const DEFAULT_MINIMUM_AMOUNT = 100;
    private const MAXIMUM_AMOUNT = 9999999999.99;

    private ?Account $resolvedAccount = null;
    private bool $accountResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'min:' . $this->effectiveMinimumAmount(),
                'max:' . $this->effectiveMaximumAmount(),
            ],
            'utr_number' => ['nullable', 'string', 'max:100', 'unique:deposits,utr_number'],
            'play_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
            'receipt_image_path' => ['nullable', 'string', 'max:2048'],
            'proof_image' => ['nullable'],
            // Optional bonus code applied on the deposit form; eligibility is
            // enforced by BonusCodeRedeemer, not here.
            'bonus_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        $hasAccountLimit = $this->accountHasLimit();
        $range = $this->cleanNumber($this->effectiveMinimumAmount())
            . ' - ' . $this->cleanNumber($this->effectiveMaximumAmount());

        return [
            'amount.decimal' => 'Amount may contain up to 2 decimal places only.',
            'amount.min' => $hasAccountLimit
                ? "Amount Not Acceptable. Allowed: {$range} INR."
                : 'Amount is below the minimum allowed.',
            'amount.max' => $hasAccountLimit
                ? "Amount Not Acceptable. Allowed: {$range} INR."
                : 'Amount exceeds the maximum allowed limit.',
        ];
    }

    /**
     * Resolve the target account from the request (once).
     */
    private function account(): ?Account
    {
        if (!$this->accountResolved) {
            $this->accountResolved = true;
            $id = $this->input('account_id');
            $this->resolvedAccount = is_numeric($id) ? Account::find($id) : null;
        }

        return $this->resolvedAccount;
    }

    private function accountHasLimit(): bool
    {
        $account = $this->account();

        return $account !== null
            && (($account->min_deposit !== null && (float) $account->min_deposit > 0)
                || ($account->max_deposit !== null && (float) $account->max_deposit > 0));
    }

    /**
     * Account min (when set) governs; otherwise the existing branch minimum.
     */
    private function effectiveMinimumAmount(): float
    {
        $accountMin = $this->account()?->min_deposit;
        if ($accountMin !== null && is_numeric($accountMin) && (float) $accountMin > 0) {
            return (float) $accountMin;
        }

        return (float) $this->resolveBranchMinimum();
    }

    /**
     * Account max (when set) governs; otherwise the global maximum.
     */
    private function effectiveMaximumAmount(): float
    {
        $accountMax = $this->account()?->max_deposit;
        if ($accountMax !== null && is_numeric($accountMax) && (float) $accountMax > 0) {
            return (float) $accountMax;
        }

        return self::MAXIMUM_AMOUNT;
    }

    private function resolveBranchMinimum(): int
    {
        $branchId = $this->user()?->branch_id;
        if (!$branchId) {
            return self::DEFAULT_MINIMUM_AMOUNT;
        }

        $minAmount = Branch::query()->whereKey($branchId)->value('min_deposit_amount');
        if ($minAmount !== null && is_numeric($minAmount)) {
            return (int) $minAmount;
        }

        return self::DEFAULT_MINIMUM_AMOUNT;
    }

    private function cleanNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
