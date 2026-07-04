<?php

namespace App\Http\Requests;

class StoreChartOfAccountRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:20'],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'type_id' => ['sometimes', 'required', 'integer', 'exists:account_types,id'],
            'parent_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'normal_balance' => ['sometimes', 'required', 'string', 'in:debit,credit'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'type_id.exists' => 'The selected account type does not exist.',
            'parent_id.exists' => 'The selected parent account does not exist.',
        ]);
    }
}
