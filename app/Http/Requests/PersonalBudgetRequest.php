<?php

namespace App\Http\Requests;

class PersonalBudgetRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'planned_amount' => ['required', 'numeric', 'min:0'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please give this budget a name.',
            'planned_amount.required' => 'Please enter a planned amount for this budget.',
            'planned_amount.min' => 'The planned amount must be 0 or more.',
            'period_end.after_or_equal' => 'The end date must be on or after the start date.',
        ]);
    }
}