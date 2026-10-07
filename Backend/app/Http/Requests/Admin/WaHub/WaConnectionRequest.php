<?php

namespace App\Http\Requests\Admin\WaHub;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Branch admin edits the text added above / below every message. Turning the
 * number on or off is a super admin action, so `enabled` is not accepted.
 */
class WaConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'header' => ['sometimes', 'nullable', 'string', 'max:500'],
            'footer' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
