<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
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
}
