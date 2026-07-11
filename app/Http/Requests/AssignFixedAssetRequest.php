<?php

namespace App\Http\Requests;

class AssignFixedAssetRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
