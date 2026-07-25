<?php

namespace App\Http\Requests;

class SalesRepRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('put') || $this->isMethod('patch')) {
            return [
                'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
                'commission_type' => ['sometimes', 'string', 'in:percentage,flat'],
                'is_active' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'email' => ['required', 'email'],
            'name' => ['sometimes', 'string', 'max:255'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_type' => ['sometimes', 'string', 'in:percentage,flat'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
