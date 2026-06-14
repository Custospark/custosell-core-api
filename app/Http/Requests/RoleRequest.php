<?php

namespace App\Http\Requests;

class RoleRequest extends BaseFormRequest
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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter a role name.',
            'slug.required' => 'Please enter a URL slug for the role.',
            'permissions.array' => 'Permissions must be provided as a list.',
            'is_default.boolean' => 'The default status must be true or false.',
        ]);
    }
}
