<?php

namespace App\Http\Requests;

class ProductSupplyListingRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listed_for_supply' => ['required', 'boolean'],
            'supply_price' => ['nullable', 'required_if:listed_for_supply,true', 'numeric', 'min:0'],
            'supply_min_qty' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'supply_price.required_if' => 'Please set a supply price before listing this product.',
        ]);
    }
}
