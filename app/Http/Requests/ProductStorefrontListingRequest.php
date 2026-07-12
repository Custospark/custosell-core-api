<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ProductStorefrontListingRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listed_for_storefront' => ['required', 'boolean'],
        ];
    }
}
