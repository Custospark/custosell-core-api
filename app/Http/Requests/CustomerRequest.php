<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = $this->user()?->business_id;
        $customerId = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:50',
                Rule::unique('customers')
                    ->where('business_id', $businessId)
                    ->ignore($customerId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'total_purchases' => ['numeric', 'min:0'],
            'last_purchase_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'A customer with this phone number already exists.',
        ];
    }
}
