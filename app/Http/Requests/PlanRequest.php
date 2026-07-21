<?php

namespace App\Http\Requests;

class PlanRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $planId = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'unique:plans,slug,' . $planId],
            'description' => ['nullable', 'string'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'price_monthly_usd' => ['nullable', 'numeric', 'min:0'],
            'price_yearly_usd' => ['nullable', 'numeric', 'min:0'],
            'onboarding_fee_ugx' => ['nullable', 'numeric', 'min:0'],
            'onboarding_fee_usd' => ['nullable', 'numeric', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'billing_cycle' => ['nullable', 'string', 'in:monthly,yearly,both'],
            'is_popular' => ['nullable', 'boolean'],
            'features' => ['required', 'array'],
            'limits' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter a plan name.',
            'slug.required' => 'Please enter a URL slug for the plan.',
            'slug.unique' => 'This slug is already in use by another plan.',
            'price_monthly.required' => 'Please enter the monthly price.',
            'price_monthly.numeric' => 'The monthly price must be a number.',
            'price_monthly.min' => 'The monthly price must be 0 or more.',
            'price_yearly.numeric' => 'The yearly price must be a number.',
            'price_yearly.min' => 'The yearly price must be 0 or more.',
            'features.required' => 'Please specify at least one feature.',
            'features.array' => 'Features must be provided as a list.',
            'limits.required' => 'Please specify at least one limit.',
            'limits.array' => 'Limits must be provided as a list.',
            'is_active.boolean' => 'The active status must be true or false.',
            'sort_order.integer' => 'The sort order must be a whole number.',
            'sort_order.min' => 'The sort order must be 0 or more.',
        ]);
    }
}
