<?php

namespace App\Http\Requests;

class StockTransferRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_location_id' => ['required', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id', 'different:from_location_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'from_location_id.required' => 'Please select the source branch.',
            'to_location_id.required' => 'Please select the destination branch.',
            'to_location_id.different' => 'Source and destination branches must be different.',
            'items.required' => 'Please select at least one product to transfer.',
            'items.min' => 'Please select at least one product to transfer.',
            'items.*.quantity.min' => 'Transfer quantities must be at least 1.',
        ]);
    }
}