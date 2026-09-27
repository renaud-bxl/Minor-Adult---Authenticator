<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Projet d'un compte client (une plateforme intégrant le module). Entité immuable hydratée depuis
 * la table `projects`. Les secrets de signature restent chiffrés : voir ProjectRepository::signingSecret().
 */
final class Project
{
    /**
     * @param list<string> $allowedOrigins origines autorisées (https://shop.example, https://*.example.com)
     * @param list<string> $methods        méthodes de vérification activées
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $accountId,
        public readonly string $name,
        public readonly int $minAge,
        public readonly int $validityDays,
        public readonly array $allowedOrigins,
        public readonly array $methods,
        public readonly bool $acceptShared,
        public readonly string $emailSalt,
        public readonly string $signingSecretTestEnc,
        public readonly string $signingSecretLiveEnc,
        /** Validité d'un résultat négatif (âge non atteint), en heures ; 0 : jamais réutilisé. */
        public readonly int $negativeTtlHours = 24,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['account_id'],
            (string) $row['name'],
            (int) $row['min_age'],
            (int) $row['validity_days'],
            self::stringList($row['allowed_origins']),
            self::stringList($row['methods']),
            (bool) $row['accept_shared'],
            (string) $row['email_salt'],
            (string) $row['signing_secret_test_enc'],
            (string) $row['signing_secret_live_enc'],
            (int) ($row['negative_ttl_hours'] ?? 24),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
