<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiscalCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $passwordRule = $this->isMethod('post')
            ? ['required', 'string', 'min:4', 'max:255']
            : ['nullable', 'string', 'min:4', 'max:255'];

        return [
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'country' => ['nullable', 'string', 'size:2'],
            'tin' => ['required', 'string', 'max:50'],
            'device_no' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'string', 'max:50'],
            'api_username' => ['required', 'string', 'max:150'],
            'api_password' => $passwordRule,
            'private_key_path' => ['nullable', 'string', 'max:255'],
            'public_key_path' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
