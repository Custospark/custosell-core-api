<?php

namespace App\Http\Requests;

class SaleItemRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'product_name' => ['required', 'string', 'max:255'],
            'product_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['numeric', 'min:0'],
            'discount_amount' => ['numeric', 'min:0'],
            'refunded_quantity' => ['integer', 'min:0'],
            'refunded_amount' => ['numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'sale_id.required' => 'The sale reference is missing.',
            'sale_id.exists' => 'The selected sale does not exist.',
            'product_id.exists' => 'The selected product does not exist.',
            'product_name.required' => 'Please enter the product name.',
            'product_price.required' => 'Please enter the product price.',
            'product_price.numeric' => 'The product price must be a number.',
            'product_price.min' => 'The product price must be 0 or more.',
            'quantity.required' => 'Please enter the quantity.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'unit_price.required' => 'Please enter the unit price.',
            'unit_price.numeric' => 'The unit price must be a number.',
            'unit_price.min' => 'The unit price must be 0 or more.',
            'subtotal.required' => 'Please enter the subtotal.',
            'subtotal.numeric' => 'The subtotal must be a number.',
            'subtotal.min' => 'The subtotal must be 0 or more.',
            'tax_amount.numeric' => 'The tax amount must be a number.',
            'tax_amount.min' => 'The tax amount must be 0 or more.',
            'discount_amount.numeric' => 'The discount amount must be a number.',
            'discount_amount.min' => 'The discount amount must be 0 or more.',
            'refunded_quantity.integer' => 'The refunded quantity must be a whole number.',
            'refunded_quantity.min' => 'The refunded quantity must be 0 or more.',
            'refunded_amount.numeric' => 'The refunded amount must be a number.',
            'refunded_amount.min' => 'The refunded amount must be 0 or more.',
        ]);
    }
}
