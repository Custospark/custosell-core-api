<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class InitiatePaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gateway_name' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_type' => ['required', 'string', 'in:onboarding,subscription,renewal,upgrade_proration,billing_cycle_change,topup'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
            'topup_months' => ['required_if:payment_type,topup', 'nullable', 'integer', 'between:1,60'],
            'phone' => ['required', 'string', 'min:6'],
            'idempotency_key' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'phone.required' => 'A mobile money phone number is required to start the payment.',
            'phone.min' => 'Please enter a valid mobile money phone number.',
        ]);
    }
}
