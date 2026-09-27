<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;

/**
 * Règles de mot de passe (recommandations NIST SP 800-63B) : longueur minimale, borne haute, pas
 * de règle de composition arbitraire, et refus d'un mot de passe identique à l'adresse e-mail.
 */
final class PasswordPolicy
{
    public function __construct(private readonly int $minLength, private readonly int $maxLength)
    {
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    public function maxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * @return array{0: string, 1: array<string, int>}|null clé de traduction et paramètres de l'erreur, ou null si valide
     */
    public function validate(string $password, string $email = ''): ?array
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < $this->minLength) {
            return ['site.validation.password_too_short', ['min' => $this->minLength]];
        }
        if ($length > $this->maxLength) {
            return ['site.validation.password_too_long', ['max' => $this->maxLength]];
        }
        if ($email !== '' && Crypto::normalizeEmail($password) === Crypto::normalizeEmail($email)) {
            return ['site.validation.password_same_as_email', []];
        }

        return null;
    }
}
