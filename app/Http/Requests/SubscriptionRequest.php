<?php

namespace App\Http\Requests;

class SubscriptionRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'string', 'in:active,trialing,cancelled,expired'],
            'starts_at' => ['required', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'business_id.required' => 'Please select a business.',
            'business_id.exists' => 'The selected business does not exist.',
            'plan_id.required' => 'Please select a plan.',
            'plan_id.exists' => 'The selected plan does not exist.',
            'status.required' => 'Please select a subscription status.',
            'status.in' => 'Please select a valid status: active, trialing, cancelled, or expired.',
            'starts_at.required' => 'Please enter the subscription start date.',
        ]);
    }
}
