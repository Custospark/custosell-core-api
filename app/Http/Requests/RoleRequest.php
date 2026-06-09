<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'is_default' => ['boolean'],
        ];
    }
}
