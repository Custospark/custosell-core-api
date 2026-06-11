<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')->where(fn($query) => $query->where('business_id', $this->user()?->business_id)),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
            'supplier_tin' => ['nullable', 'string', 'max:100'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_claimable' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_interval' => ['nullable', 'string', 'in:daily,weekly,monthly,yearly'],
            'recurrence_end_date' => ['nullable', 'date', 'after:expense_date'],
            'next_due_date' => ['nullable', 'date'],
            'expense_date' => ['required', 'date'],
        ];
    }
}
