<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jeton CSRF synchronisé par session : un jeton aléatoire de 256 bits, comparé en temps constant.
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = $this->rotate();
        }

        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(?string $candidate): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' && is_string($candidate) && hash_equals($token, $candidate);
    }
}
