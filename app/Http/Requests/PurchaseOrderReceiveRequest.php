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
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.create_product' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $index => $item) {
                $productId = $item['product_id'] ?? null;
                $create = filter_var($item['create_product'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (! $create && empty($productId)) {
                    $validator->errors()->add(
                        "items.{$index}",
                        'Map this line to a local product or choose “Create new product”.'
                    );
                }
                if ($create && ! empty($productId)) {
                    $validator->errors()->add(
                        "items.{$index}",
                        'Choose either an existing product or create a new one, not both.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'items.required' => 'Map each line to a local product (or create one) to receive this order.',
            'items.*.product_id.exists' => 'The selected local product does not exist.',
        ]);
    }
}
