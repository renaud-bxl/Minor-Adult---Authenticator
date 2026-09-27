<?php

declare(strict_types=1);

return [
    'driver' => (string) env('MAIL_DRIVER', 'smtp'),
    'host' => (string) env('MAIL_HOST', '127.0.0.1'),
    'port' => (int) env('MAIL_PORT', 587),
    'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
    'username' => (string) env('MAIL_USERNAME', ''),
    'password' => (string) env('MAIL_PASSWORD', ''),
    'from_address' => (string) env('MAIL_FROM_ADDRESS', ''),
    'from_name' => (string) env('MAIL_FROM_NAME', 'VeriAge'),
    'outbox' => (string) env('MAIL_OUTBOX', 'storage/mail'),
];
