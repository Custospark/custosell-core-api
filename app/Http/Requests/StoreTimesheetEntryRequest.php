<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreTimesheetEntryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'project_task_id' => ['nullable', 'integer', 'exists:project_tasks,id'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'entry_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_billable' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
        ];
    }
}
