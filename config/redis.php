<?php

declare(strict_types=1);

return [
    'host' => (string) env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'password' => (string) env('REDIS_PASSWORD', ''),
    'database' => (int) env('REDIS_DB', 0),
    'prefix' => (string) env('REDIS_PREFIX', 'veriage:'),
];
