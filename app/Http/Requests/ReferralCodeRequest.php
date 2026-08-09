<?php

namespace App\Http\Requests;

use App\Enums\Billing\DiscountType;
use App\Models\Plan;
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
            $ref = $this->route('referral_code') ?? $this->route('id');
            $existing = $ref ? \App\Models\ReferralCode::whereKey($ref)->first() : null;

            // Effective owner type: what is being written (create), else what is stored (update).
            $ownerType = $this->input('owner_type') ?? $existing?->owner_type?->value;

            // Sales-rep codes are ALWAYS single-period (see SalesRepService::create).
            // The referee discount applies to the first charge only; a longer duration
            // would create recurring monthly discount credits — the one recurring
            // company cost — with no extra rep earnings. This clamp blocks raising it
            // through the admin referral-code CRUD surface too.
            if ($ownerType === \App\Enums\Billing\ReferralCodeOwnerType::SALES_REP->value
                && (int) ($this->input('discount_duration_months') ?? 1) !== 1) {
                $validator->errors()->add('discount_duration_months', 'Sales-rep codes are always single-period (discount_duration_months = 1).');
            }

            // Campaign codes are company-owned promos: discount-only and single-period.
            // Same structural guard as sales-rep codes so the company keeps the largest
            // share (Company > Referee on the cheapest plan) and never credits itself.
            if ($ownerType === \App\Enums\Billing\ReferralCodeOwnerType::CAMPAIGN->value) {
                // Single-period clamp fires whenever a duration is supplied or a code is
                // being created; status-only toggles (no duration) are not blocked.
                if (($this->isMethod('post') || $this->exists('discount_duration_months'))
                    && (int) ($this->input('discount_duration_months') ?? 1) !== 1) {
                    $validator->errors()->add('discount_duration_months', 'Campaign codes are single-period (discount_duration_months = 1).');
                }

                // Discount-only: campaign codes never carry a reward (the company would be crediting itself).
                // Only enforced when a reward is actually being submitted so unrelated updates pass.
                $rewardSubmitted = $this->exists('reward_type') || $this->exists('reward_value');
                $rewardType = $this->input('reward_type') ?? $existing?->reward_type?->value;
                $rewardValue = (float) ($this->input('reward_value') ?? $existing?->reward_value ?? 0);
                if ($rewardSubmitted && ($rewardType !== null || $rewardValue > 0)) {
                    $validator->errors()->add('reward_value', 'Campaign codes are discount-only — they never carry a reward.');
                }

                // Discount cap so Company > Referee holds on the cheapest plan.
                // Only enforced when the discount value is being submitted (or creating)
                // so a status toggle on a legacy out-of-zone code is not blocked.
                $discountSubmitted = $this->exists('discount_value') || $this->isMethod('post');
                if ($discountSubmitted) {
                    $type = $this->input('discount_type') ?? $existing?->discount_type?->value ?? DiscountType::PERCENTAGE->value;
                    $value = (float) ($this->input('discount_value') ?? $existing?->discount_value ?? 0);

                    if ($type === DiscountType::PERCENTAGE->value && $value > 30) {
                        $validator->errors()->add('discount_value', 'Campaign percentage discount is capped at 30% so the company keeps the largest share.');
                    } elseif ($type === DiscountType::FLAT_AMOUNT->value) {
                        $minOnboarding = (float) Plan::query()
                            ->where('is_active', true)
                            ->where('onboarding_fee_usd', '>', 0)
                            ->min('onboarding_fee_usd');
                        $minOnboarding = $minOnboarding > 0 ? $minOnboarding : 40.0;
                        if ($value >= $minOnboarding / 2) {
                            $validator->errors()->add('discount_value', 'Campaign flat discount must be below half the cheapest plan fee so the company keeps the largest share.');
                        }
                    }
                }
            }
        });
    }
}
