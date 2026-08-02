<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class LocationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $locationId = $this->route('location');

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('locations', 'code')->where('business_id', $this->user()->business_id)->ignore($locationId)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter a location name.',
            'name.max' => 'The location name must not exceed 150 characters.',
            'code.unique' => 'This location code is already in use for your business.',
        ]);
    }
}
