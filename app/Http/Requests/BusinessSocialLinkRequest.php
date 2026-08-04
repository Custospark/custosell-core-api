<?php

namespace App\Http\Requests;

class BusinessSocialLinkRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'max:50'],
            'url' => ['required', 'url', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'platform.required' => 'Please enter a platform name.',
            'platform.max' => 'The platform name must not exceed 50 characters.',
            'url.required' => 'Please enter the social link URL.',
            'url.url' => 'The social link must be a valid URL (include https://).',
            'url.max' => 'The social link must not exceed 255 characters.',
        ]);
    }
}