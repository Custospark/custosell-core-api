<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreEstimateRequest extends BaseFormRequest
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
            'pipeline_lead_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_leads', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'parent_estimate_id' => [
                'nullable',
                'integer',
                Rule::exists('estimates', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'discount_type' => ['nullable', 'string', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'line_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'line_items.*.type' => ['nullable', 'string', 'in:labor,material,equipment,other'],
            'line_items.*.description' => ['required', 'string', 'max:1000'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'line_items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.markup_type' => ['nullable', 'string', 'in:percent,fixed,none'],
            'line_items.*.markup_value' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.is_billable' => ['nullable', 'boolean'],
        ];
    }
}
