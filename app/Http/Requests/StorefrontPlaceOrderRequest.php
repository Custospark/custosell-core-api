<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StorefrontPlaceOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'customer_name.required' => 'Please enter your name.',
            'customer_phone.required' => 'Please enter your phone number so the shop can reach you.',
            'items.required' => 'Add at least one product to your order.',
        ]);
    }
}
