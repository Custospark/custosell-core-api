<?php

namespace App\Http\Requests;

class BusinessRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = $this->route('id') ?? $this->route('business');
        return [
            'owner_name' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:businesses,slug,' . $businessId],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'tax_regime' => ['nullable', 'string', 'in:none,vat_registered'],
            'jurisdiction' => ['nullable', 'string', 'max:8'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prices_include_tax' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'business_email' => ['nullable', 'string', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'business_type' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'max:10'],
            'receipt_footer' => ['nullable', 'string'],
            'payment_bank_name' => ['nullable', 'string', 'max:150'],
            'payment_bank_account_name' => ['nullable', 'string', 'max:150'],
            'payment_bank_account_number' => ['nullable', 'string', 'max:80'],
            'payment_bank_branch' => ['nullable', 'string', 'max:150'],
            'payment_mobile_money_provider' => ['nullable', 'string', 'max:100'],
            'payment_mobile_money_account_name' => ['nullable', 'string', 'max:150'],
            'payment_mobile_money_number' => ['nullable', 'string', 'max:50'],
            'payment_instructions' => ['nullable', 'string'],
            'logo_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'slug.unique' => 'This business URL slug is already taken.',
            'website.url' => 'Please enter a valid website URL (e.g. https://example.com).',
            'tax_regime.in' => 'Tax regime must be either none or vat_registered.',
        ]);
    }
}
