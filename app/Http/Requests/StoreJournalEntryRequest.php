<?php

namespace App\Http\Requests;

class StoreJournalEntryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:50'],
            'reference_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.debit_amount' => ['numeric', 'min:0'],
            'lines.*.credit_amount' => ['numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'lines.required' => 'At least two journal entry lines are required.',
            'lines.min' => 'At least two journal entry lines are required.',
            'lines.*.account_id.exists' => 'The selected account in line :position does not exist.',
            'lines.*.debit_amount.numeric' => 'The debit amount in line :position must be a number.',
            'lines.*.credit_amount.numeric' => 'The credit amount in line :position must be a number.',
        ]);
    }
}
