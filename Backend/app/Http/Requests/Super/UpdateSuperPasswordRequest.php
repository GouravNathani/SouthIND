<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSuperPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'old_password' => ['required', 'string', 'min:8'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
