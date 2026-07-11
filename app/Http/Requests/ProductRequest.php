<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProductRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');
        $businessId = $this->user()?->business_id;

        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:product,service'],
            'description' => ['nullable', 'string'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('business_id', $businessId))
                    ->ignore($productId),
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['integer', 'min:0'],
            'low_stock_threshold' => ['integer', 'min:0'],
            'tax_percentage' => ['numeric', 'min:0', 'max:100'],
            'tax_class' => ['nullable', 'string', 'in:standard,exempt,zero_rated'],
            'is_active' => ['boolean'],
            'is_recurring' => ['boolean'],
            'billing_interval' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = $this->input('type', 'product');
        if ($type === 'service') {
            $this->merge([
                'type' => 'service',
                'stock_quantity' => 0,
            ]);
        }
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter a product name.',
            'type.in' => 'Type must be product or service.',
            'category_id.exists' => 'The selected category does not exist.',
            'sku.unique' => 'This SKU is already in use by another product.',
            'unit_price.required' => 'Please enter the unit price.',
            'unit_price.numeric' => 'The unit price must be a number.',
            'unit_price.min' => 'The unit price must be 0 or more.',
            'wholesale_price.numeric' => 'The wholesale price must be a number.',
            'wholesale_price.min' => 'The wholesale price must be 0 or more.',
            'cost_price.numeric' => 'The cost price must be a number.',
            'cost_price.min' => 'The cost price must be 0 or more.',
            'stock_quantity.integer' => 'The stock quantity must be a whole number.',
            'stock_quantity.min' => 'The stock quantity must be 0 or more.',
            'low_stock_threshold.integer' => 'The low stock threshold must be a whole number.',
            'low_stock_threshold.min' => 'The low stock threshold must be 0 or more.',
            'tax_percentage.numeric' => 'The tax percentage must be a number.',
            'tax_percentage.min' => 'The tax percentage must be 0 or more.',
            'tax_percentage.max' => 'The tax percentage must not exceed 100.',
            'tax_class.in' => 'Please select a valid tax class: standard, exempt, or zero-rated.',
            'is_active.boolean' => 'The active status must be true or false.',
        ]);
    }
}
