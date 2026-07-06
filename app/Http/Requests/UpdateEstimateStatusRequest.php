<?php

namespace App\Http\Requests;

class UpdateEstimateStatusRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_by_name' => ['nullable', 'string', 'max:255'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
            'change_summary' => ['nullable', 'string', 'max:500'],
        ];
    }
}
