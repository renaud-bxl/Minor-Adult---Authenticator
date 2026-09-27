<?php

declare(strict_types=1);

$env = (string) env('APP_ENV', 'production');

return [
    'name' => (string) env('APP_NAME', 'VeriAge'),
    'env' => $env,
    // Le mode debug est ignoré en production, quelle que soit la valeur de APP_DEBUG.
    'debug' => $env !== 'production' && (bool) env('APP_DEBUG', false),
    'domain' => (string) env('APP_DOMAIN', ''),
    'url' => rtrim((string) env('APP_URL', ''), '/'),
    'timezone' => (string) env('APP_TIMEZONE', 'Europe/Brussels'),
    'key' => (string) env('APP_KEY', ''),
    'log_level' => (string) env('LOG_LEVEL', 'info'),
    'log_path' => 'storage/logs',
    // Rétention des journaux applicatifs (jours), purge automatique à la rotation quotidienne.
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 30),
];
