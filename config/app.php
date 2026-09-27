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
    // Module de vérification (API, page hébergée, widget) : sous-domaine « verify. ».
    'verify_url' => rtrim((string) env('VERIFY_URL', ''), '/'),
    // Plateforme cliente fictive de démonstration : jamais en production, sauf DEMO_ENABLED=true.
    'demo_url' => rtrim((string) env('DEMO_URL', ''), '/'),
    'demo_enabled' => $env !== 'production' || (bool) env('DEMO_ENABLED', false),
    // Projet de démonstration (sandbox) : php bin/project.php demo.
    'demo_api_key' => (string) env('DEMO_API_KEY', ''),
    'demo_signing_secret' => (string) env('DEMO_SIGNING_SECRET', ''),
    'timezone' => (string) env('APP_TIMEZONE', 'Europe/Brussels'),
    'key' => (string) env('APP_KEY', ''),
    'log_level' => (string) env('LOG_LEVEL', 'info'),
    'log_path' => 'storage/logs',
    // Rétention des journaux applicatifs (jours), purge automatique à la rotation quotidienne.
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 30),
];
