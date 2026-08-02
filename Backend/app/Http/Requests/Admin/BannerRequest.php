<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'title' => ['nullable', 'string', 'max:190'],
            // allow legacy string path, single file, or multiple files
            'image_path' => [
                $isCreate ? 'required_without_all:image,images' : 'sometimes',
                'string',
                'max:2048',
            ],
            'image' => [
                $isCreate ? 'required_without_all:image_path,images' : 'sometimes',
                'file',
                'image',
                'max:5120', // ~5MB
            ],
            'images' => [
                $isCreate ? 'required_without_all:image_path,image' : 'sometimes',
                'array',
                'min:1',
            ],
            'images.*' => [
                'file',
                'image',
                'max:5120',
            ],
            'is_logo' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
