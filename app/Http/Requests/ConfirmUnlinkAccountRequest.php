<?php

namespace App\Http\Requests;

class ConfirmUnlinkAccountRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'code.required' => 'Enter the security code sent to the account you are unlinking.',
            'code.size' => 'The security code must be 6 digits.',
        ]);
    }
}
