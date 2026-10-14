<?php

return [
    'admin_email' => env('SUPPORT_ADMIN_EMAIL'),
    'admin_password' => env('SUPPORT_ADMIN_PASSWORD'),
    'auto_assign' => env('TICKET_AUTO_ASSIGN', true),
    'attachment_disk' => env('TICKET_ATTACHMENT_DISK', 'private'),
    'import_stale_seconds' => (int) env('TICKET_IMPORT_STALE_SECONDS', 1800),
    'monitor' => [
        'outbox_max_age_seconds' => (int) env('SUPPORT_OUTBOX_MAX_AGE_SECONDS', 300),
        'heartbeat_max_age_seconds' => (int) env('SUPPORT_HEARTBEAT_MAX_AGE_SECONDS', 300),
        'queue_max_jobs' => (int) env('SUPPORT_QUEUE_MAX_JOBS', 1000),
        'queue_max_age_seconds' => (int) env('SUPPORT_QUEUE_MAX_AGE_SECONDS', 300),
        'alert_cooldown_seconds' => (int) env('SUPPORT_ALERT_COOLDOWN_SECONDS', 300),
    ],
    'import_max_rows' => (int) env('TICKET_IMPORT_MAX_ROWS', 100000),
];
