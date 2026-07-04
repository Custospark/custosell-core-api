<?php

namespace App\Http\Requests;

class StoreFixedAssetRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['required', 'numeric', 'min:0', 'lte:cost'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'purchase_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'account_id.exists' => 'The selected account does not exist.',
            'salvage_value.lte' => 'The salvage value must be less than or equal to the cost.',
            'useful_life_months.min' => 'The useful life must be at least 1 month.',
        ]);
    }
}
