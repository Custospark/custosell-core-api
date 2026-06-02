<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockMovementRequest extends FormRequest
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
}
