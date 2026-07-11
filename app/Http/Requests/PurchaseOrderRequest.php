<?php

namespace App\Http\Requests;

class PurchaseOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $itemsRule = $isCreate ? ['required', 'array', 'min:1'] : ['sometimes', 'array', 'min:1'];

        return [
            'seller_business_id' => $isCreate
                ? ['required', 'integer', 'exists:businesses,id']
                : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => $itemsRule,
            'items.*.product_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'seller_business_id.required' => 'Please choose a supplier to order from.',
            'seller_business_id.exists' => 'The selected supplier does not exist.',
            'items.required' => 'A purchase order must include at least one item.',
        ]);
    }
}
