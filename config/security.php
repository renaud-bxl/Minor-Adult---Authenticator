<?php

declare(strict_types=1);

return [
    'crypto_key' => (string) env('CRYPTO_KEY', ''),
    // Rotation de CRYPTO_KEY : version de la clé courante (1 à 255, écrite dans chaque chiffré) et
    // anciennes clés gardées pour déchiffrer (« 1:base64:…,2:base64:… ») jusqu'à bin/reencrypt.php.
    'crypto_key_version' => (int) env('CRYPTO_KEY_VERSION', 1),
    'crypto_previous_keys' => (string) env('CRYPTO_PREVIOUS_KEYS', ''),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),
    // Désactivé par défaut : sur un VPS partagé (domaine de recette), includeSubDomains imposerait
    // HTTPS aux autres sites hébergés sous le même domaine.
    'hsts_include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),

    // Proxys inverses dont l'en-tête X-Forwarded-For est cru (adresses ou CIDR, séparés par des virgules).
    // Vide par défaut : sous HestiaCP, Apache restaure déjà l'IP réelle (mod_remoteip). À renseigner
    // (ex. « 127.0.0.1 ») seulement si REMOTE_ADDR vaut l'adresse du nginx frontal.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    'session' => [
        // ASVS V3.3.2 (niveau 2) : réauthentification après 30 minutes d'inactivité.
        'idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 30),
        'absolute_minutes' => (int) env('SESSION_ABSOLUTE_MINUTES', 720),
        'secure_cookie' => (bool) env('SESSION_SECURE_COOKIE', true),
    ],

    'password' => [
        'min_length' => 12,
        // Borne haute : évite qu'un mot de passe géant serve à saturer Argon2id.
        'max_length' => 1024,
        // Liste locale des mots de passe courants ou compromis (voir PasswordBlocklist).
        'blocklist' => 'resources/security/common-passwords.txt',
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

        // API (phase 2) : par IP avant authentification, par clé après, échecs d'authentification par IP.
        'api_ip' => [300, 60],
        'api_key' => [600, 60],
        'api_auth_failure_ip' => [20, 300],
        // Sessions créées pour une même adresse (par projet et par mode) : anti-bombardement de codes.
        'api_session_email' => [10, 3600],
        // Page de vérification hébergée et codes : seuils lus dans .env, adaptés à la CGNAT des réseaux
        // mobiles (des milliers d'abonnés derrière une IP). Codes : clé (IP, projet), et non IP seule.
        'verify_page_ip' => [(int) env('RATE_VERIFY_PAGE_IP_PER_MINUTE', 600), 60],
        'verify_code_send_ip' => [(int) env('RATE_VERIFY_CODE_SEND_IP_PER_HOUR', 100), 3600],
        // Saisies de code par (IP, projet), et codes erronés par adresse (projet + mode) toutes sessions confondues.
        'verify_code_ip' => [(int) env('RATE_VERIFY_CODE_IP_PER_HOUR', 300), 3600],
        'verify_code_email' => [10, 86400],
        // Codes erronés par adresse tous clients confondus (production) : seuil de la preuve renforcée.
        'verify_code_global' => [(int) env('VERIFICATION_GLOBAL_CODE_FAILURES', 20), 86400],
        // Démonstration : sessions sandbox créées par IP.
        'demo_session_ip' => [60, 3600],
    ],
];
