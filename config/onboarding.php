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
];
