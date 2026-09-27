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
        return password_hash(self::normalize($password), PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify(self::normalize($password), $hash);
    }

    /**
     * Normalisation NFC avant hachage (NIST SP 800-63B §5.1.1.2) : un même mot de passe saisi sur
     * deux claviers ou systèmes (« é » précomposé ou décomposé) donne le même hash.
     */
    private static function normalize(string $password): string
    {
        $normalized = \Normalizer::normalize($password, \Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $password;
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
