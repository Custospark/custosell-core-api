<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StorefrontFavoriteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'min:1', 'exists:businesses,id'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'business_id.required' => 'Select a business to favorite.',
            'business_id.exists' => 'This business is no longer available.',
        ]);
    }
}