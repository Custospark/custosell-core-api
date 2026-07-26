<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReferralCodeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $codeId = $this->route('referral_code') ?? $this->route('id');

        $rules = [
            'owner_type' => ['required', 'string', 'in:business,sales_rep,campaign'],
            'owner_business_id' => ['sometimes', 'integer', 'exists:businesses,id'],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'discount_type' => ['required', 'string', 'in:percentage,flat_amount,free_month'],
            'discount_value' => ['sometimes', 'numeric', 'min:0', 'required_if:discount_type,percentage,flat_amount'],
            'discount_duration_months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'reward_type' => ['sometimes', 'string', 'in:percentage,flat_amount,free_month'],
            'reward_value' => ['sometimes', 'numeric', 'min:0'],
            'max_uses' => ['sometimes', 'integer', 'min:1', 'nullable'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'date', 'nullable'],
        ];

        $rules['code'] = $codeId
            ? ['sometimes', 'string', 'max:64', Rule::unique('referral_codes', 'code')->ignore($codeId)]
            : ['sometimes', 'string', 'max:64', 'unique:referral_codes,code'];

        return $rules;
    }
}
