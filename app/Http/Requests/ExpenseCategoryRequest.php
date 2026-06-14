<?php

namespace App\Http\Requests;

class ExpenseCategoryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'budget_period' => ['nullable', 'string', 'in:weekly,monthly,quarterly,yearly'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter an expense category name.',
            'name.string' => 'The category name must be text.',
            'name.max' => 'The category name must not exceed 255 characters.',
            'budget_amount.numeric' => 'The budget amount must be a number.',
            'budget_amount.min' => 'The budget amount must be 0 or more.',
            'budget_period.in' => 'Please select a valid budget period: weekly, monthly, quarterly, or yearly.',
            'sort_order.integer' => 'The sort order must be a whole number.',
            'sort_order.min' => 'The sort order must be 0 or more.',
        ]);
    }
}
