<?php

namespace App\Http\Requests\Admin\WaHub;

use Illuminate\Foundation\Http\FormRequest;

/** A test message; `to` defaults to the admin's own phone. */
class WaTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['nullable', 'string', 'max:32'],
            'text' => ['required', 'string', 'max:2000'],
        ];
    }
}
