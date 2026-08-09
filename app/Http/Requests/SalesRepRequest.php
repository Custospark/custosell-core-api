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
                'discount_rate' => ['sometimes', 'numeric', 'min:0', 'max:30'],
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
            'password' => ['sometimes', 'string', 'min:6'],
            'commission_rate' => ['required', 'numeric', 'min:0'],
            'discount_rate' => ['sometimes', 'numeric', 'min:0', 'max:30'],
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

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $type = $this->input('commission_type', 'percentage');
            if ($type !== 'percentage') {
                // Flat commission is an absolute amount; only the discount cap applies.
                return;
            }

            $discount = (float) ($this->input('discount_rate') ?? 20);
            $commission = (float) ($this->input('commission_rate') ?? 0);

            // Safe zone so Company > Referrer > Referee always holds:
            //   commission must sit strictly between d/(1-d) and 50 %.
            $minCommission = round($discount / (100 - $discount) * 100, 2);
            if ($commission <= $minCommission) {
                $validator->errors()->add('commission_rate', "For a {$discount}% referee discount, commission must be greater than {$minCommission}% so the referrer earns more than the referee saves.");
            }
            if ($commission >= 50) {
                $validator->errors()->add('commission_rate', 'Commission must be below 50% so the company keeps the largest share.');
            }
        });
    }
}