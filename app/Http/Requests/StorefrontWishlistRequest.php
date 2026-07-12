<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StorefrontWishlistRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'product_id.required' => 'Select a product to save.',
            'product_id.exists' => 'This product is no longer available.',
        ]);
    }
}
