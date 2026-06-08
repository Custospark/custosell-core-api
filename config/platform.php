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

    'notification_channels' => ['email', 'in_app', 'both'],

    'default_notification_channel' => env('PLATFORM_DEFAULT_NOTIFICATION_CHANNEL', 'both'),
];

