<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UserChangeMpinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'old_mpin' => ['required', 'digits:6'],
            'new_mpin' => ['required', 'digits:6', 'different:old_mpin'],
        ];
    }
}
