<?php

namespace App\Http\Requests;

class StoreAccountingPeriodRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
        ]);
    }
}
