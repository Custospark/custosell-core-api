<?php

namespace App\Http\Requests\Billing;

use App\Http\Requests\BaseFormRequest;

class EmailReceiptRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
