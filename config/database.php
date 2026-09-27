<?php

declare(strict_types=1);

return [
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_DATABASE', 'veriage'),
    'username' => (string) env('DB_USERNAME', 'veriage'),
    'password' => (string) env('DB_PASSWORD', ''),
    'migrations_path' => 'database/migrations',
];
