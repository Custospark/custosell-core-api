<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ShiftRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'clock_in' => $isUpdate ? ['nullable', 'date'] : ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('business_id', $this->user()->business_id)),
            ],
            'total_sales' => ['numeric', 'min:0'],
            'total_cash' => ['numeric', 'min:0'],
            'total_mobile_money' => ['numeric', 'min:0'],
            'total_card' => ['numeric', 'min:0'],
            'status' => ['string', 'in:active,completed'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'clock_in.required' => 'Please enter the clock-in time.',
            'clock_out.after' => 'The clock-out time must be after the clock-in time.',
            'opening_balance.numeric' => 'The opening balance must be a number.',
            'opening_balance.min' => 'The opening balance must be 0 or more.',
            'counted_cash.numeric' => 'The counted cash must be a number.',
            'counted_cash.min' => 'The counted cash must be 0 or more.',
            'total_sales.numeric' => 'The total sales must be a number.',
            'total_sales.min' => 'The total sales must be 0 or more.',
            'total_cash.numeric' => 'The total cash must be a number.',
            'total_cash.min' => 'The total cash must be 0 or more.',
            'total_mobile_money.numeric' => 'The total mobile money must be a number.',
            'total_mobile_money.min' => 'The total mobile money must be 0 or more.',
            'total_card.numeric' => 'The total card payments must be a number.',
            'total_card.min' => 'The total card payments must be 0 or more.',
            'status.in' => 'Please select a valid shift status: active or completed.',
        ]);
    }
}
