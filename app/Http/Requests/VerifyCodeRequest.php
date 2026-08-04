<?php

namespace App\Http\Requests;

class VerifyCodeRequest extends BaseFormRequest
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
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'Please enter your email address.',
            'purpose.in' => 'Invalid verification purpose.',
            'code.required' => 'Please enter your security code.',
            'code.digits' => 'The security code must be 6 digits.',
        ]);
    }
}