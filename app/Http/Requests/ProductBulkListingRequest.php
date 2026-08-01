<?php

namespace App\Http\Requests;

class ProductBulkListingRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['required', 'integer', 'exists:products,id'],
            'channel' => ['required', 'string', 'in:supply,storefront'],
            'listed' => ['required', 'boolean'],
        ];
    }
}
