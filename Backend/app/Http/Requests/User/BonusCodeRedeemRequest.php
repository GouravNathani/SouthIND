<?php

namespace App\Http\Requests\User;

use App\Models\BonusCode;
use Illuminate\Foundation\Http\FormRequest;

class BonusCodeRedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => BonusCode::normalise($this->input('code'))]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            // Present when the user is checking a code from the deposit form.
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
