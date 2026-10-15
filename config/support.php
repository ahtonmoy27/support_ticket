<?php

return [
    'notifications_queue' => env('QUEUE_NOTIFICATIONS_NAME', 'notifications'),
    'admin_email' => env('SUPPORT_ADMIN_EMAIL', 'admin@gmail.com'),
    'admin_password' => env('SUPPORT_ADMIN_PASSWORD', 'password123321'),
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
    'notifications' => [
        'admin' => [
            'ticket_created' => (bool) env('NOTIFY_ADMIN_TICKET_CREATED', true),
            'ticket_assigned' => (bool) env('NOTIFY_ADMIN_TICKET_ASSIGNED', false),
            'customer_replied' => (bool) env('NOTIFY_ADMIN_CUSTOMER_REPLIED', false),
            'agent_replied' => (bool) env('NOTIFY_ADMIN_AGENT_REPLIED', false),
            'internal_note' => (bool) env('NOTIFY_ADMIN_INTERNAL_NOTE', true),
            'status_changed' => (bool) env('NOTIFY_ADMIN_STATUS_CHANGED', false),
            'sla_warning' => (bool) env('NOTIFY_ADMIN_SLA_WARNING', true),
            'sla_breach' => (bool) env('NOTIFY_ADMIN_SLA_BREACH', true),
        ],
        'customer' => [
            'ticket_assigned' => (bool) env('NOTIFY_CUSTOMER_TICKET_ASSIGNED', true),
            'sla_warning' => (bool) env('NOTIFY_CUSTOMER_SLA_WARNING', false),
            'sla_breach' => (bool) env('NOTIFY_CUSTOMER_SLA_BREACH', false),
        ],
        'agent' => [
            'internal_note' => (bool) env('NOTIFY_AGENT_INTERNAL_NOTE', true),
        ],
    ],
];
