<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StaffTransferRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'from_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'transfer_type' => ['sometimes', 'string', Rule::in(['permanent', 'temporary'])],
            'status' => ['sometimes', 'string', Rule::in(['pending', 'completed', 'cancelled'])],
            'approval_required' => ['sometimes', 'boolean'],
            'approved_by' => ['nullable', 'integer', 'exists:users,id'],
            'approved_at' => ['nullable', 'date'],
            'effective_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'old_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'new_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'old_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'new_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'old_salary' => ['nullable', 'numeric', 'min:0'],
            'new_salary' => ['nullable', 'numeric', 'min:0'],
            'old_employment_type' => ['nullable', 'string', 'max:40'],
            'new_employment_type' => ['nullable', 'string', 'max:40'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'user_id.required' => 'Select the staff member to transfer.',
            'to_location_id.required' => 'Select the destination branch.',
            'end_at.after_or_equal' => 'The return date must be on or after the effective date.',
        ]);
    }
}
