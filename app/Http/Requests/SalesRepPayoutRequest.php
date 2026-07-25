<?php

namespace App\Http\Requests;

class SalesRepPayoutRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'paid_at' => ['sometimes', 'date'],
        ];
    }
}
