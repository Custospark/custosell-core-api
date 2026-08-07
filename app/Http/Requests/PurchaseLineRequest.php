<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}