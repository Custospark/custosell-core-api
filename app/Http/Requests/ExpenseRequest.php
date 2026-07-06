<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ExpenseRequest extends BaseFormRequest
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
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where(fn($query) => $query->where('business_id', $this->user()?->business_id)),
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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'expense_category_id.exists' => 'The selected expense category does not exist.',
            'shift_id.exists' => 'The selected shift does not exist or is not part of your business.',
            'amount.required' => 'Please enter the expense amount.',
            'amount.numeric' => 'The expense amount must be a number.',
            'amount.min' => 'The expense amount must be 0 or more.',
            'description.required' => 'Please provide a description for the expense.',
            'receipt.file' => 'The receipt must be a file.',
            'receipt.mimes' => 'The receipt must be a JPG, JPEG, PNG, or PDF file.',
            'receipt.max' => 'The receipt must not exceed 5 MB.',
            'recurrence_interval.in' => 'Please select a valid recurrence interval: daily, weekly, monthly, or yearly.',
            'recurrence_end_date.after' => 'The recurrence end date must be after the expense date.',
            'expense_date.required' => 'Please select the expense date.',
            'vat_amount.numeric' => 'The VAT amount must be a number.',
            'vat_amount.min' => 'The VAT amount must be 0 or more.',
            'vat_claimable.boolean' => 'The VAT claimable field must be true or false.',
            'is_recurring.boolean' => 'The recurring field must be true or false.',
            'reference.max' => 'The reference must not exceed 255 characters.',
            'supplier_tin.max' => 'The supplier TIN must not exceed 100 characters.',
            'supplier_invoice_no.max' => 'The supplier invoice number must not exceed 100 characters.',
        ]);
    }
}
