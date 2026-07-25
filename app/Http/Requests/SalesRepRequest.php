<?php

namespace App\Http\Requests;

class SalesRepRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('put') || $this->isMethod('patch')) {
            return [
                'commission_rate' => ['sometimes', 'numeric', 'min:0'],
                'commission_type' => ['sometimes', 'string', 'in:percentage,flat'],
                'is_active' => ['sometimes', 'boolean'],
                'phone' => ['sometimes', 'string', 'max:50'],
                'region' => ['sometimes', 'string', 'max:100'],
                'payment_method' => ['sometimes', 'nullable', 'string', 'in:mobile_money,bank'],
                'mobile_money_provider' => ['sometimes', 'nullable', 'string', 'max:50'],
                'mobile_money_number' => ['sometimes', 'nullable', 'string', 'max:50'],
                'mobile_money_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'bank_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
                'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            ];
        }

        return [
            'email' => ['required', 'email'],
            'name' => ['sometimes', 'string', 'max:255'],
            'commission_rate' => ['required', 'numeric', 'min:0'],
            'commission_type' => ['sometimes', 'string', 'in:percentage,flat'],
            'is_active' => ['sometimes', 'boolean'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'in:mobile_money,bank'],
            'mobile_money_provider' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile_money_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile_money_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
