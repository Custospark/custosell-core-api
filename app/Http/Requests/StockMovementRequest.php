<?php

namespace App\Http\Requests;

class StockMovementRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'sale_item_id' => ['nullable', 'integer', 'exists:sale_items,id'],
            'type' => ['required', 'string', 'in:purchase,sale,adjustment,return,initial'],
            'quantity_change' => ['required', 'integer'],
            'stock_before' => ['required', 'integer', 'min:0'],
            'stock_after' => ['required', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'product_id.required' => 'Please select a product.',
            'product_id.exists' => 'The selected product does not exist.',
            'sale_item_id.exists' => 'The selected sale item does not exist.',
            'type.required' => 'Please select the movement type.',
            'type.in' => 'Please select a valid movement type: purchase, sale, adjustment, return, or initial.',
            'quantity_change.required' => 'Please enter the quantity change.',
            'quantity_change.integer' => 'The quantity change must be a whole number.',
            'stock_before.required' => 'Please enter the stock level before the movement.',
            'stock_before.integer' => 'The stock before must be a whole number.',
            'stock_before.min' => 'The stock before must be 0 or more.',
            'stock_after.required' => 'Please enter the stock level after the movement.',
            'stock_after.integer' => 'The stock after must be a whole number.',
            'stock_after.min' => 'The stock after must be 0 or more.',
            'reference.max' => 'The reference must not exceed 255 characters.',
        ]);
    }
}
