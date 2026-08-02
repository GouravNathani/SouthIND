<?php

namespace App\Http\Requests\Admin;

use App\Models\BonusCodeRedemption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BonusRedemptionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    BonusCodeRedemption::STATUS_FULFILLED,
                    BonusCodeRedemption::STATUS_REJECTED,
                ]),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
