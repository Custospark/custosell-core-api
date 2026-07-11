<?php

namespace App\Http\Requests;

class PurchaseOrderReceiveRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'items.required' => 'Map each line to a local product to receive this order.',
            'items.*.product_id.exists' => 'The selected local product does not exist.',
        ]);
    }
}
