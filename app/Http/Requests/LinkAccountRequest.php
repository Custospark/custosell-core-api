<?php

namespace App\Http\Requests;

class LinkAccountRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'Please enter the account email address.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Please enter the account password.',
        ]);
    }
}
