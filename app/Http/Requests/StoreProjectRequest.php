<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreProjectRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'estimate_id' => [
                'nullable',
                'integer',
                Rule::exists('estimates', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'pipeline_lead_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_leads', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:planning,active,on_hold,completed,cancelled'],
            'currency' => ['nullable', 'string', 'max:10'],
            'budget_revenue' => ['nullable', 'numeric', 'min:0'],
            'budget_cost' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
        ];
    }
}
