<?php

declare(strict_types=1);

$env = (string) env('APP_ENV', 'production');

return [
    // Durée de vie d'une session de vérification (secondes) : « expires_in » de POST /api/v1/sessions.
    'session_ttl' => 1800,
    // Âges minimaux acceptés (projet et session).
    'allowed_min_ages' => [16, 18, 21],
    // Durée de validité par défaut d'une vérification (jours), réglable par projet.
    'default_validity_days' => 365,

    // Contrôle de l'adresse e-mail : code à 6 chiffres.
    'email_code' => [
        'ttl' => 600,
        // Codes erronés tolérés par session avant échec de la session.
        'max_attempts' => 5,
        // Envois de code par session (premier envoi compris) et délai minimal entre deux envois.
        'max_sends' => 3,
        'resend_interval' => 60,
    ],

    // Blocage d'une adresse après X échecs (codes épuisés, vérification échouée), par projet et par mode.
    'failure_lock' => [
        'max_failures' => (int) env('VERIFICATION_MAX_FAILURES', 5),
        'window' => 86400,
    ],

    // Jeton de retour (redirection return_url?session_id=&token=) : JWT HS256 court.
    'return_token_ttl' => 300,

    // Webhooks : relances exponentielles (base × 2^(n-1), plafonnées), puis abandon.
    'webhooks' => [
        'max_attempts' => 10,
        'base_delay' => 30,
        'max_delay' => 21600,
        'connect_timeout' => 5,
        'timeout' => 10,
        // Tolérance d'horodatage de la signature (anti-rejeu), documentée pour les clients.
        'signature_tolerance' => 300,
    ],

    // Réseaux privés, boucle locale et http:// pour les webhooks et return_url : UNIQUEMENT en
    // développement (démo locale). Refusé au démarrage en production (ConfigValidator).
    'allow_private_network' => $env !== 'production' && (bool) env('VERIFICATION_ALLOW_PRIVATE_NETWORK', false),
    'allow_private_network_requested' => (bool) env('VERIFICATION_ALLOW_PRIVATE_NETWORK', false),

    // Rétention (jours) : sessions terminées ou expirées, livraisons de webhooks, journal d'audit.
    'retention' => [
        'sessions_days' => 30,
        'deliveries_days' => 30,
        'audit_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
        // Comptes clients jamais validés.
        'unverified_accounts_days' => 7,
    ],
];
