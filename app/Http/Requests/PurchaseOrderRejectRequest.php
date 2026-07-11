<?php

namespace App\Http\Requests;

class PurchaseOrderRejectRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'rejection_reason.required' => 'Please explain why this purchase order is being rejected.',
        ]);
    }
}
