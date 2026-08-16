<?php

namespace App\Http\Requests;

class ConfirmLinkAccountRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_user_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'code.required' => 'Enter the security code sent to the account you are linking.',
            'code.size' => 'The security code must be 6 digits.',
            'target_user_id.required' => 'The account to link is required.',
        ]);
    }
}
