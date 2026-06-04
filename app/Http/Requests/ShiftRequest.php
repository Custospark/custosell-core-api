<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShiftRequest extends FormRequest
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
}
