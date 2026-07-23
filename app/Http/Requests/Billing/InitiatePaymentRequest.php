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
            'payment_type' => ['required', 'string', 'in:onboarding,subscription,renewal,upgrade_proration'],
            'phone' => ['sometimes', 'string'],
        ];
    }
}
