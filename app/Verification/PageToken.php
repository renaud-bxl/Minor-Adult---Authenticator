<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * Jeton signé des formulaires de la page hébergée, qui fonctionne sans aucun cookie (les cookies
 * tiers sont bloqués dans les iframes) : HMAC-SHA256(clé dérivée, identifiant de session | expiration).
 * Il remplace le jeton CSRF de session : un formulaire forgé ailleurs, sans ce jeton, est refusé (419).
 */
final class PageToken
{
    public const FIELD = '_state';

    public function __construct(private readonly string $key, private readonly int $ttl = 3600)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Clé de jeton trop courte.');
        }
    }

    public function issue(string $sessionId, int $now): string
    {
        $expires = $now + $this->ttl;

        return $expires . '.' . $this->mac($sessionId, $expires);
    }

    public function validate(string $token, string $sessionId, int $now): bool
    {
        if (preg_match('/^(\d{10,12})\.([A-Za-z0-9_-]{43})$/D', $token, $m) !== 1 || (int) $m[1] < $now) {
            return false;
        }

        return hash_equals($this->mac($sessionId, (int) $m[1]), $m[2]);
    }

    private function mac(string $sessionId, int $expires): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $sessionId . '|' . $expires, $this->key, true)), '+/', '-_'), '=');
    }
}
