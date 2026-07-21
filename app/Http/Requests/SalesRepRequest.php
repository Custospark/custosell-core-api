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
        return [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:sales_reps,user_id'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_type' => ['sometimes', 'string', 'in:percentage,flat'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
