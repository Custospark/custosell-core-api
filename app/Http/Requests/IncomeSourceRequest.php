<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class IncomeSourceRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_id' => [
                'nullable',
                'integer',
                Rule::exists('personal_budgets', 'id')->where(fn($query) => $query->where('business_id', $this->user()?->business_id)),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'source_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'income_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'amount.required' => 'Please enter the income amount.',
            'budget_id.exists' => 'The selected budget does not exist or is not part of your business.',
            'amount.numeric' => 'The income amount must be a number.',
            'amount.min' => 'The income amount must be 0 or more.',
            'source_name.required' => 'Please enter the income source name.',
            'source_name.max' => 'The source name must not exceed 255 characters.',
            'income_date.required' => 'Please select the income date.',
        ]);
    }
}
