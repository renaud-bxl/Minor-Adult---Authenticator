<?php

declare(strict_types=1);

namespace App\Services;

/** Hachage des mots de passe en Argon2id, paramètres issus de config/security.php. */
final class PasswordHasher
{
    /** @param array{memory_cost: int, time_cost: int, threads: int} $options */
    public function __construct(private readonly array $options)
    {
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /**
     * Consomme le même temps qu'une vérification quand le compte n'existe pas : la durée de la
     * réponse ne révèle donc pas si une adresse est inscrite.
     */
    public function simulateVerification(): void
    {
        $this->hash(random_bytes(16));
    }
}
