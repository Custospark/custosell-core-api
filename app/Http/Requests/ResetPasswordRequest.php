<?php

namespace App\Http\Requests;

class ResetPasswordRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'token.required' => 'The reset token is missing. Please try resetting your password again.',
            'password.required' => 'Please enter a new password.',
            'password.min' => 'Your new password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);
    }
}
