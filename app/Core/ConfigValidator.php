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
}
