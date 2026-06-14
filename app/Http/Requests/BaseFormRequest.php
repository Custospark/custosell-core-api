<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    protected const COMMON_MESSAGES = [
        'required' => 'The :attribute is required.',
        'string' => 'The :attribute must be text.',
        'integer' => 'The :attribute must be a whole number.',
        'numeric' => 'The :attribute must be a number.',
        'boolean' => 'The :attribute must be true or false.',
        'array' => 'The :attribute must be a list.',
        'date' => 'The :attribute is not a valid date.',
        'email' => 'Please enter a valid email address.',
        'confirmed' => 'Password confirmation does not match.',
        'url' => 'The :attribute must be a valid URL.',
        'file' => 'The :attribute must be a file.',
        'image' => 'The :attribute must be an image file.',
        'min' => [
            'numeric' => 'The :attribute must be at least :min.',
            'file' => 'The :attribute must be at least :min kilobytes.',
            'string' => 'The :attribute must be at least :min characters.',
            'array' => 'The :attribute must have at least :min items.',
        ],
        'max' => [
            'numeric' => 'The :attribute must not exceed :max.',
            'file' => 'The :attribute must not exceed :max kilobytes.',
            'string' => 'The :attribute must not exceed :max characters.',
            'array' => 'The :attribute must not have more than :max items.',
        ],
        'exists' => 'The selected :attribute does not exist.',
        'unique' => 'This :attribute is already taken.',
        'in' => 'The selected :attribute is not valid.',
        'after' => 'The :attribute must be after :date.',
        'mimes' => 'The :attribute must be a file of type: :values.',
    ];

    public function messages(): array
    {
        return static::COMMON_MESSAGES;
    }
}
