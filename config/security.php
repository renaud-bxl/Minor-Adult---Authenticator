<?php

declare(strict_types=1);

return [
    'crypto_key' => (string) env('CRYPTO_KEY', ''),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    'session' => [
        'idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => (int) env('SESSION_ABSOLUTE_MINUTES', 720),
        'secure_cookie' => (bool) env('SESSION_SECURE_COOKIE', true),
    ],

    'password' => [
        'min_length' => 12,
        // Borne haute : évite qu'un mot de passe géant serve à saturer Argon2id.
        'max_length' => 1024,
        'argon2' => [
            'memory_cost' => (int) env('PASSWORD_ARGON2_MEMORY', 65536),
            'time_cost' => (int) env('PASSWORD_ARGON2_TIME', 4),
            'threads' => (int) env('PASSWORD_ARGON2_THREADS', 1),
        ],
    ],

    // Durées de validité des jetons envoyés par e-mail (secondes).
    'tokens' => [
        'email_verification_ttl' => 86400,
        'password_reset_ttl' => 3600,
    ],

    // Limitation de débit : [nombre maximal de tentatives, fenêtre glissante en secondes].
    'rate_limits' => [
        'login_email' => [5, 900],
        'login_ip' => [30, 900],
        'register_ip' => [10, 3600],
        'password_reset_ip' => [10, 3600],
        'password_reset_email' => [3, 3600],
        'verification_resend_user' => [3, 3600],
    ],
];
