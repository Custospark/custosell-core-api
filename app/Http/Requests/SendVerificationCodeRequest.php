<?php

namespace App\Http\Requests;

class SendVerificationCodeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'purpose' => ['required', 'string', 'in:email_verification,two_factor'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'purpose.in' => 'Invalid verification purpose.',
        ]);
    }
}