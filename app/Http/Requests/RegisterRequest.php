<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use App\Services\ModuleAccessService;

class RegisterRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
            'account_type' => ['sometimes', 'string', Rule::in(['storefront_buyer'])],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleAccessService::assignableModuleSlugs())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('account_type') === 'storefront_buyer') {
            $this->merge([
                'business_id' => null,
                'role_id' => null,
                'modules' => [],
            ]);
        }
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter your full name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'Please enter a password.',
            'password.min' => 'Your password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'business_id.exists' => 'The selected business does not exist.',
            'role_id.exists' => 'The selected role does not exist.',
            'modules.array' => 'Modules must be provided as a list.',
            'modules.*.in' => 'One or more selected modules are not valid.',
        ]);
    }
}
