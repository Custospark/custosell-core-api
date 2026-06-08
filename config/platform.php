<?php

return [
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('PLATFORM_ADMIN_EMAILS', '')),
    ))),

    'activity_window_days' => (int) env('PLATFORM_ACTIVITY_WINDOW_DAYS', 30),

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
];

