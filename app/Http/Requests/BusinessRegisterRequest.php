<?php

namespace App\Http\Requests;

class BusinessRegisterRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:businesses,slug'],
            'privacy_consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'privacy_consent.accepted' => 'You must agree to the Data & Privacy Policy to create an account.',
        ]);
    }
}
