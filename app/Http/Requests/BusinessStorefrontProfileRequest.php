<?php

declare(strict_types=1);

namespace App\Http\Requests;

class BusinessStorefrontProfileRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storefront_enabled' => ['required', 'boolean'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
