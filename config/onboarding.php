<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Onboarding Trial Period
    |--------------------------------------------------------------------------
    |
    | Number of days the onboarding trial lasts after successful payment.
    | During this period, the business has full access to their chosen plan.
    | After the trial ends, a subscription payment is required to continue.
    |
    */
    'trial_days' => env('ONBOARDING_TRIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Onboarding Fee Marked Paid At Account Creation
    |--------------------------------------------------------------------------
    |
    | When true, business-account subscriptions created at registration are
    | created with onboarding_fee_paid = true (no "Pay Setup Fee" prompt), even
    | if the chosen plan carries a non-zero onboarding fee. Set to false when
    | you want new business accounts to pay the plan's onboarding fee before
    | activation. Keeps setup-fee behaviour flexible per environment.
    |
    */
    'fee_paid_on_create' => env('ONBOARDING_FEE_PAID_ON_CREATE', false),
];
