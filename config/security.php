<?php

declare(strict_types=1);

return [
    'crypto_key' => (string) env('CRYPTO_KEY', ''),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),
    // Désactivé par défaut : sur un VPS partagé (domaine de recette), includeSubDomains imposerait
    // HTTPS aux autres sites hébergés sous le même domaine.
    'hsts_include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),

    // Proxys inverses dont l'en-tête X-Forwarded-For est cru (adresses ou CIDR, séparés par des virgules).
    // Vide par défaut : sous HestiaCP, Apache restaure déjà l'IP réelle (mod_remoteip). À renseigner
    // (ex. « 127.0.0.1 ») seulement si REMOTE_ADDR vaut l'adresse du nginx frontal.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

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
        // Connexion : par IP (attaque de nombreux comptes), par couple adresse + IP (attaque ciblée)
        // et par adresse toutes IP confondues (attaque distribuée). Le plafond global est plus haut :
        // un tiers ne peut pas bloquer un compte depuis une seule IP (5 tentatives au plus par IP).
        'login_ip' => [30, 900],
        'login_email_ip' => [5, 900],
        'login_email' => [20, 3600],
        'register_ip' => [10, 3600],
        // E-mails envoyés à une même adresse via l'inscription (anti-bombardement du titulaire).
        'register_email' => [3, 3600],
        'password_reset_ip' => [10, 3600],
        'password_reset_email' => [3, 3600],
        'verification_resend_user' => [3, 3600],
    ],
];
