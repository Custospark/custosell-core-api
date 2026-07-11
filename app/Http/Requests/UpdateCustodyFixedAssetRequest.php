<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCustodyFixedAssetRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'cost' => ['sometimes', 'required', 'numeric', 'min:0'],
            'salvage_value' => array_values(array_filter([
                'sometimes',
                'required',
                'numeric',
                'min:0',
                $this->filled('cost') ? 'lte:cost' : null,
            ])),
            'useful_life_months' => ['sometimes', 'required', 'integer', 'min:1'],
            'purchase_date' => ['sometimes', 'required', 'date'],
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
            'notes' => ['nullable', 'string'],
        ];
    }
}
