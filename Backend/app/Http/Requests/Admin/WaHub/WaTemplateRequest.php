<?php

namespace App\Http\Requests\Admin\WaHub;

use Illuminate\Foundation\Http\FormRequest;

/** Saved campaign text: both fields on create, either on update. */
class WaTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'body' => [$required, 'string', 'max:2000'],
        ];
    }
}
