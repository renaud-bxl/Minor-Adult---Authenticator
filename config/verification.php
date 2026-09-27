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
    // Validité par défaut d'un résultat NÉGATIF (âge non atteint), en heures, réglable par projet (0 à 720).
    // Courte : sans date de naissance conservée, on ignore quand la personne atteindra l'âge requis.
    // 0 : jamais réutilisé (chaque demande relance une vérification). Un échec technique n'est jamais réutilisé.
    'default_negative_ttl_hours' => (int) env('VERIFICATION_NEGATIVE_TTL_HOURS', 24),
    // Fenêtre (secondes) pendant laquelle un jeton de retour peut être émis après la fin de la session ;
    // au-delà, le résultat reste affiché sans jeton (le client s'appuie sur le webhook ou l'API).
    'return_token_window' => (int) env('VERIFICATION_RETURN_TOKEN_WINDOW', 600),
    // Preuve renforcée : au-delà de ce nombre de codes erronés pour une adresse en 24 h, tous clients
    // confondus (production), le code à 6 chiffres est remplacé par un lien à usage unique. Pas de blocage.
    'global_code_failures' => (int) env('VERIFICATION_GLOBAL_CODE_FAILURES', 20),
    // Validité du lien à usage unique (secondes).
    'magic_link_ttl' => 900,

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
