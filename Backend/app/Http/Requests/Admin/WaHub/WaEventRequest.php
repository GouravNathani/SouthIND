<?php

namespace App\Http\Requests\Admin\WaHub;

use Illuminate\Foundation\Http\FormRequest;

/** Event on/off and its text. `body: null` resets the text to the default. */
class WaEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
