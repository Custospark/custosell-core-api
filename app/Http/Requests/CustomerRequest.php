<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class CustomerRequest extends BaseFormRequest
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
        return array_merge(parent::messages(), [
            'phone.unique' => 'A customer with this phone number already exists.',
            'email.email' => 'Please enter a valid email address.',
        ]);
    }
}
