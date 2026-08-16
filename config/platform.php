<?php

return [
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('PLATFORM_ADMIN_EMAILS', '')),
    ))),

    // Businesses with sale or login within this window are "active".
    'activity_window_days' => (int) env('PLATFORM_ACTIVITY_WINDOW_DAYS', 30),

    // After active window but within this window → "dormant". Beyond → "churned".
    'activity_dormant_days' => (int) env('PLATFORM_ACTIVITY_DORMANT_DAYS', 90),

    'business_statuses' => ['active', 'warning', 'restricted', 'suspended', 'notified'],

    'blocked_business_statuses' => ['restricted', 'suspended'],

    'notification_intentions' => [
        'announcement',
        'warning_notice',
        'payment_reminder',
        'policy_update',
        'reactivation_nudge',
        'custom',
    ],

    'user_notification_intentions' => [
        'announcement',
        'warning_notice',
        'policy_update',
        'reactivation_nudge',
        'account_notice',
        'custom',
    ],

    'notification_channels' => ['email', 'in_app', 'both'],

    'default_notification_channel' => env('PLATFORM_DEFAULT_NOTIFICATION_CHANNEL', 'both'),

    // Custospark's own accounting ledger (the company books), where Custosell
    // subscription revenue and payouts are booked. The owner email of the
    // business used as the company ledger defaults to the company owner
    // (oscar@custospark.com).
    'company_accounting' => [
        'owner_email' => strtolower(trim((string) env('COMPANY_ACCOUNT_EMAIL', 'oscar@custospark.com'))),
        'account_codes' => [
            'bank' => env('COMPANY_ACCOUNT_CODE_BANK', '1102'),
            'deferred_revenue' => env('COMPANY_ACCOUNT_CODE_DEFERRED_REVENUE', '2106'),
            'software_revenue' => env('COMPANY_ACCOUNT_CODE_SOFTWARE_REVENUE', '4500'),
            'referral_commission_expense' => env('COMPANY_ACCOUNT_CODE_REFERRAL_COMMISSION', '6901'),
        ],
        'reference_types' => [
            'subscription_payment' => 'platform_subscription_payment',
            'payout' => 'platform_payout',
        ],
    ],
];

