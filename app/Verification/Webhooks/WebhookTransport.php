<?php

declare(strict_types=1);

namespace App\Verification\Webhooks;

/** Envoi HTTP d'un webhook vers une adresse IP déjà vérifiée (voir UrlGuard::resolvePublic). */
interface WebhookTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: ?int, error: ?string} code HTTP reçu, ou code d'erreur réseau
     */
    public function post(string $url, string $ip, array $headers, string $body, int $connectTimeout, int $timeout): array;
}
