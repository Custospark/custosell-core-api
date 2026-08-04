<?php

namespace App\Http\Requests;

class PasswordChangeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'current_password.required' => 'Please enter your current password.',
            'password.min' => 'Your new password must be at least 6 characters.',
            'password.confirmed' => 'The new password confirmation does not match.',
        ]);
    }
}