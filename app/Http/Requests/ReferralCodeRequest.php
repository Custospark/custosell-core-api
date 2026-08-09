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

        $isCreate = $this->isMethod('post');

        $rules = [
            'owner_type' => [$isCreate ? 'required' : 'sometimes', 'string', 'in:business,sales_rep,campaign'],
            'owner_business_id' => ['sometimes', 'integer', 'exists:businesses,id'],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'discount_type' => [$isCreate ? 'required' : 'sometimes', 'string', 'in:percentage,flat_amount,free_month'],
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

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            // Sales-rep codes are ALWAYS single-period (see SalesRepService::create).
            // The referee discount applies to the first charge only; a longer duration
            // would create recurring monthly discount credits — the one recurring
            // company cost — with no extra rep earnings. This clamp blocks raising it
            // through the admin referral-code CRUD surface too.
            $ref = $this->route('referral_code') ?? $this->route('id');
            $isRepCode = $ref && \App\Models\ReferralCode::whereKey($ref)->first()?->owner_type
                === \App\Enums\Billing\ReferralCodeOwnerType::SALES_REP;

            if ($isRepCode && (int) ($this->input('discount_duration_months') ?? 1) !== 1) {
                $validator->errors()->add('discount_duration_months', 'Sales-rep codes are always single-period (discount_duration_months = 1).');
            }
        });
    }
}
