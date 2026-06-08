<?php

return [
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('PLATFORM_ADMIN_EMAILS', '')),
    ))),

    'activity_window_days' => (int) env('PLATFORM_ACTIVITY_WINDOW_DAYS', 30),
];
