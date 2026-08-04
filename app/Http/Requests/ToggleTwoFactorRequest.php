<?php

namespace App\Http\Requests;

class ToggleTwoFactorRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'enabled.required' => 'Please specify whether two-factor authentication should be enabled.',
            'enabled.boolean' => 'Invalid two-factor setting.',
        ]);
    }
}