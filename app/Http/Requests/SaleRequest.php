<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'receipt_number' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_total' => ['numeric', 'min:0'],
            'discount_amount' => ['numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,card,other'],
            'payment_status' => ['string', 'in:paid,partially_refunded,refunded'],
            'notes' => ['nullable', 'string'],
            'sale_date' => ['required', 'date'],
        ];
    }
}
