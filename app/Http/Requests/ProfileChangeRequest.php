<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProfileChangeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $this->user()->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email address.',
            'email.unique' => 'This email address is already in use by another account.',
            'avatar.image' => 'The avatar must be an image file.',
            'avatar.mimes' => 'The avatar must be a JPG, JPEG, PNG, GIF, or WebP file.',
            'avatar.max' => 'The avatar must not exceed 2 MB.',
        ]);
    }
}