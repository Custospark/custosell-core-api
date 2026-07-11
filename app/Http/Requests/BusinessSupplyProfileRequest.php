<?php

namespace App\Http\Requests;

class BusinessSupplyProfileRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_open_for_supply' => ['required', 'boolean'],
            'supply_headline' => ['nullable', 'string', 'max:255'],
        ];
    }
}
