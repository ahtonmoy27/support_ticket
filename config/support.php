<?php

return [
    'admin_email' => env('SUPPORT_ADMIN_EMAIL'),
    'admin_password' => env('SUPPORT_ADMIN_PASSWORD'),
    'auto_assign' => env('TICKET_AUTO_ASSIGN', true),
    'attachment_disk' => env('TICKET_ATTACHMENT_DISK', 'private'),
    'import_max_rows' => (int) env('TICKET_IMPORT_MAX_ROWS', 100000),
];
