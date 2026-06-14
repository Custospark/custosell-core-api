<?php

namespace App\Http\Requests;

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
