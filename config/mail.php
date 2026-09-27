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
    // Nom d'hôte du Message-ID et du HELO SMTP (sinon PHPMailer prend le nom de la machine).
    'hostname' => (string) env('APP_DOMAIN', ''),
    'outbox' => (string) env('MAIL_OUTBOX', 'storage/mail'),
    // « redis » : e-mails mis en file (chiffrés) et envoyés par bin/worker.php, avec relances ;
    // « sync » : envoyés par le processus web après la réponse (développement sans worker).
    'queue' => (string) env('MAIL_QUEUE', 'redis'),
    'max_attempts' => 5,
];
