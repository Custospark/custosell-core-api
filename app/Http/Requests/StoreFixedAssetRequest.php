<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreFixedAssetRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isPut = $this->isMethod('PUT');
        $financialRequired = $isPut ? 'sometimes' : 'required';

        return [
            'name' => [$financialRequired, 'string', 'max:200'],
            'account_id' => [
                $isPut ? 'sometimes' : 'nullable',
                'integer',
                'exists:chart_of_accounts,id',
            ],
            'cost' => [$financialRequired, 'numeric', 'min:0'],
            'salvage_value' => array_values(array_filter([
                $financialRequired,
                'numeric',
                'min:0',
                $this->filled('cost') ? 'lte:cost' : null,
            ])),
            'useful_life_months' => [$financialRequired, 'integer', 'min:1'],
            'purchase_date' => [$financialRequired, 'date'],
            'status' => ['sometimes', 'string', 'in:active,disposed,fully_depreciated,sold'],
            'notes' => ['nullable', 'string'],
            'asset_tag' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('fixed_assets', 'asset_tag')
                    ->where(fn ($query) => $query->where('business_id', $this->user()?->business_id))
                    ->ignore($this->route('id')),
            ],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'in:laptop,phone,furniture,vehicle,other'],
            'location' => ['nullable', 'string', 'max:200'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor,retired'],
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
