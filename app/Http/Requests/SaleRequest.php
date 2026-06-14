<?php

namespace App\Http\Requests;

class SaleRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'change_given' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,card,other'],
            'notes' => ['nullable', 'string'],
            'sale_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'customer_id.exists' => 'The selected customer does not exist.',
            'shift_id.exists' => 'The selected shift does not exist.',
            'items.required' => 'Please add at least one item to the sale.',
            'items.array' => 'Items must be provided as a list.',
            'items.min' => 'Please add at least one item to the sale.',
            'items.*.product_id.required' => 'Each item must have a product selected.',
            'items.*.product_id.exists' => 'One or more selected products do not exist.',
            'items.*.quantity.required' => 'Each item must have a quantity.',
            'items.*.quantity.integer' => 'Item quantities must be whole numbers.',
            'items.*.quantity.min' => 'Each item quantity must be at least 1.',
            'items.*.unit_price.required' => 'Each item must have a unit price.',
            'items.*.unit_price.numeric' => 'Item unit prices must be numbers.',
            'items.*.unit_price.min' => 'Item unit prices must be 0 or more.',
            'subtotal.required' => 'Please enter the subtotal.',
            'subtotal.numeric' => 'The subtotal must be a number.',
            'subtotal.min' => 'The subtotal must be 0 or more.',
            'tax_total.numeric' => 'The tax total must be a number.',
            'tax_total.min' => 'The tax total must be 0 or more.',
            'discount_amount.numeric' => 'The discount must be a number.',
            'discount_amount.min' => 'The discount must be 0 or more.',
            'total_amount.required' => 'Please enter the total amount.',
            'total_amount.numeric' => 'The total amount must be a number.',
            'total_amount.min' => 'The total amount must be 0 or more.',
            'amount_tendered.numeric' => 'The amount tendered must be a number.',
            'amount_tendered.min' => 'The amount tendered must be 0 or more.',
            'change_given.numeric' => 'The change given must be a number.',
            'change_given.min' => 'The change given must be 0 or more.',
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'Please select a valid payment method: cash, mobile money, card, or other.',
            'sale_date.date' => 'The sale date is not valid.',
        ]);
    }
}
