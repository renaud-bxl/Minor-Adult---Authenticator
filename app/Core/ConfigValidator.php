<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contrôle de la configuration de production au démarrage : une erreur de .env doit empêcher
 * l'application de démarrer plutôt que produire des liens relatifs dans les e-mails, des cookies
 * non sécurisés ou une clé de chiffrement invalide découverte au premier usage.
 * Les messages ne contiennent jamais la valeur des secrets.
 */
final class ConfigValidator
{
    /** Seuil « même personne » publié par OpenCV pour SFace (similarité cosinus). */
    private const SFACE_SAME_PERSON = 0.363;

    /**
     * @param bool $web requête HTTP (et non script CLI) : la libération anticipée du client via
     *                  fastcgi_finish_request() est alors exigée (anti-énumération par le temps)
     * @return list<string> problèmes détectés (vide si la configuration est valide)
     */
    public static function productionProblems(Config $config, bool $web): array
    {
        $problems = [];

        $url = (string) $config->get('app.url');
        if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_HOST) === null) {
            $problems[] = 'APP_URL doit être une URL absolue en https://';
        }
        $verifyUrl = (string) $config->get('app.verify_url');
        if (!str_starts_with($verifyUrl, 'https://') || parse_url($verifyUrl, PHP_URL_HOST) === null) {
            $problems[] = 'VERIFY_URL doit être une URL absolue en https://';
        }
        if ($config->get('verification.allow_private_network_requested') === true) {
            $problems[] = 'VERIFICATION_ALLOW_PRIVATE_NETWORK est interdit en production (SSRF)';
        }
        if (!in_array($config->get('mail.queue'), ['redis', 'sync'], true)) {
            $problems[] = 'MAIL_QUEUE doit valoir redis ou sync';
        }
        if ((string) $config->get('app.domain') === '') {
            $problems[] = 'APP_DOMAIN est vide';
        }
        foreach (['app.key' => 'APP_KEY', 'security.crypto_key' => 'CRYPTO_KEY'] as $key => $name) {
            try {
                Crypto::decodeKey((string) $config->get($key));
            } catch (\InvalidArgumentException) {
                $problems[] = $name . ' doit contenir 32 octets encodés en base64';
            }
        }
        if ((string) $config->get('app.key') !== '' && $config->get('app.key') === $config->get('security.crypto_key')) {
            $problems[] = 'APP_KEY et CRYPTO_KEY doivent être différentes';
        }
        $problems = [...$problems, ...self::keyringProblems($config), ...self::moduleLimitProblems($config), ...self::biometricsProblems($config)];
        $demoKey = (string) $config->get('app.demo_api_key');
        if ($config->get('app.demo_enabled') === true && $demoKey !== '' && !str_starts_with($demoKey, 'sk_test_')) {
            $problems[] = 'DEMO_API_KEY doit être une clé sandbox (sk_test_…) : la démonstration ne crée jamais de session de production';
        }
        if ($config->get('mail.driver') !== 'smtp') {
            $problems[] = 'MAIL_DRIVER doit valoir smtp';
        }
        if (filter_var((string) $config->get('mail.from_address'), FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'MAIL_FROM_ADDRESS invalide';
        }
        if ($config->get('security.session.secure_cookie') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE doit valoir true';
        }
        if ((int) $config->get('security.session.idle_minutes') > 30) {
            $problems[] = 'SESSION_IDLE_MINUTES ne doit pas dépasser 30 (ASVS V3.3.2)';
        }
        if ($web && !function_exists('fastcgi_finish_request')) {
            $problems[] = 'PHP-FPM requis (fastcgi_finish_request absent)';
        }

        return $problems;
    }

    /**
     * Trousseau de chiffrement (rotation de CRYPTO_KEY) : version courante 1 à 255, anciennes clés au
     * format « version:base64:… », versions distinctes, clé courante absente des anciennes.
     *
     * @return list<string>
     */
    private static function keyringProblems(Config $config): array
    {
        try {
            $current = Crypto::decodeKey((string) $config->get('security.crypto_key'));
        } catch (\InvalidArgumentException) {
            return []; // déjà signalé (CRYPTO_KEY)
        }
        try {
            $previous = Crypto::parseKeyring((string) $config->get('security.crypto_previous_keys', ''));
            new Crypto($current, $current, (int) $config->get('security.crypto_key_version', 1), $previous);
        } catch (\InvalidArgumentException) {
            return ['CRYPTO_KEY_VERSION (1 à 255) ou CRYPTO_PREVIOUS_KEYS (« version:base64:… », versions distinctes de la courante) invalide'];
        }

        return in_array($current, $previous, true) ? ['CRYPTO_PREVIOUS_KEYS ne doit pas contenir la clé courante'] : [];
    }

    /**
     * Biométrie locale (si activée) : secret, service sur la boucle locale seulement, seuils cohérents et
     * dans les bornes acceptées par le microservice, dossier temporaire hors de public/.
     *
     * @return list<string>
     */
    public static function biometricsProblems(Config $config): array
    {
        if ($config->get('biometrics.enabled') !== true) {
            return [];
        }
        $problems = [];
        if (strlen((string) $config->get('biometrics.secret')) < 32) {
            $problems[] = 'BIOMETRICS_SECRET doit contenir au moins 32 caractères (php bin/generate-keys.php)';
        }
        try {
            \App\Verification\Biometrics\BiometricsClient::assertLoopbackUrl((string) $config->get('biometrics.url'));
        } catch (\InvalidArgumentException) {
            $problems[] = 'BIOMETRICS_URL doit viser la boucle locale (http://127.0.0.1:port ou http://[::1]:port)';
        }
        $match = (float) $config->get('biometrics.face_match_threshold');
        $review = (float) $config->get('biometrics.face_review_threshold');
        // Plancher d'acceptation : 0,363 est le seuil « même personne » publié par OpenCV pour SFace ; en
        // dessous, on accepterait automatiquement des paires que le modèle juge de deux personnes (la
        // fraude visée : la pièce d'un aîné). Une calibration plus basse relève de la revue, pas de l'acceptation.
        if ($match < self::SFACE_SAME_PERSON || $match > 1 || $review <= 0 || $review > $match) {
            $problems[] = 'BIOMETRICS_FACE_MATCH_THRESHOLD et BIOMETRICS_FACE_REVIEW_THRESHOLD : 0 < revue ≤ acceptation ≤ 1, acceptation ≥ 0,363 (SFace)';
        }
        // Revue manuelle désactivée en V1 (audit phase 3, E3) : seule la valeur « fail » est admise.
        if ($config->get('biometrics.below_threshold_default') !== 'fail') {
            $problems[] = 'BIOMETRICS_BELOW_THRESHOLD doit valoir fail (revue manuelle désactivée en V1)';
        }
        $ttl = (int) $config->get('biometrics.review_ttl_hours');
        $timeout = (int) $config->get('biometrics.timeout');
        if ($ttl < 1 || $ttl > 168 || $timeout < 5 || $timeout > 120) {
            $problems[] = 'BIOMETRICS_REVIEW_TTL_HOURS (1 à 168) ou BIOMETRICS_TIMEOUT (5 à 120 s) hors limites';
        }
        $bounds = ['yaw_threshold' => [0.1, 0.8, 'BIOMETRICS_LIVENESS_YAW'], 'blink_ratio' => [0.3, 0.9, 'BIOMETRICS_LIVENESS_BLINK_RATIO'],
            'same_face_min' => [0.1, 0.9, 'BIOMETRICS_LIVENESS_SAME_FACE'], 'replay_threshold' => [1.0, 1000.0, 'BIOMETRICS_LIVENESS_REPLAY']];
        foreach ($bounds as $key => [$low, $high, $env]) {
            $value = (float) $config->get('biometrics.liveness.' . $key);
            if ($value < $low || $value > $high) {
                $problems[] = $env . ' doit être compris entre ' . $low . ' et ' . $high;
            }
        }
        $tmp = (string) $config->get('biometrics.tmp_dir');
        if ($tmp === '' || str_contains('/' . trim($tmp, '/') . '/', '/public/')) {
            $problems[] = 'BIOMETRICS_TMP_DIR doit être défini, hors de public/';
        }

        return $problems;
    }

    /**
     * Réglages du module lus dans .env : une valeur absente de la plage (ou non numérique, lue 0 par
     * « (int) ») bloquerait tout le monde (limite à 0) ou désactiverait une protection.
     *
     * @return list<string>
     */
    private static function moduleLimitProblems(Config $config): array
    {
        $problems = [];
        $limits = [
            'verify_page_ip' => ['RATE_VERIFY_PAGE_IP_PER_MINUTE', 10_000],
            'verify_code_ip' => ['RATE_VERIFY_CODE_IP_PER_HOUR', 10_000],
            'verify_code_send_ip' => ['RATE_VERIFY_CODE_SEND_IP_PER_HOUR', 10_000],
            'verify_code_global' => ['VERIFICATION_GLOBAL_CODE_FAILURES', 1_000],
        ];
        foreach ($limits as $name => [$env, $ceiling]) {
            $max = (int) ($config->get('security.rate_limits.' . $name) ?? [0])[0];
            if ($max < 1 || $max > $ceiling) {
                $problems[] = $env . ' doit être un entier de 1 à ' . $ceiling;
            }
        }
        $negativeTtl = (int) $config->get('verification.default_negative_ttl_hours', 24);
        if ($negativeTtl < 0 || $negativeTtl > 720) {
            $problems[] = 'VERIFICATION_NEGATIVE_TTL_HOURS doit être compris entre 0 et 720';
        }
        $window = (int) $config->get('verification.return_token_window', 600);
        if ($window < 60 || $window > 3600) {
            $problems[] = 'VERIFICATION_RETURN_TOKEN_WINDOW doit être compris entre 60 et 3600 secondes';
        }

        return $problems;
    }
}
