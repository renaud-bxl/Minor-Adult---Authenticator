<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Projets et secrets de signature. Un secret (« whsec_… ») par projet et par mode signe les webhooks
 * (HMAC-SHA256) et le jeton de retour (JWT HS256) : il est chiffré en base (AES-256-GCM, lié au
 * projet et au mode) et n'est affiché en clair qu'une fois, à la création ou à la rotation.
 */
final class ProjectRepository
{
    public const SECRET_PREFIX = 'whsec_';

    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $methods
     * @return array{0: Project, 1: array{test: string, live: string}} projet et secrets en clair
     */
    public function create(
        int $accountId,
        string $name,
        int $minAge,
        int $validityDays,
        array $allowedOrigins,
        array $methods,
        bool $acceptShared,
    ): array {
        $publicId = 'prj_' . Crypto::randomAlnum(20);
        $secrets = ['test' => self::newSecret(), 'live' => self::newSecret()];
        $id = $this->db->insert(
            'INSERT INTO projects (public_id, account_id, name, min_age, validity_days, allowed_origins, methods, accept_shared, '
            . 'email_salt, signing_secret_test_enc, signing_secret_live_enc, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                $publicId, $accountId, $name, $minAge, $validityDays,
                json_encode(array_values($allowedOrigins), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                json_encode(array_values($methods), JSON_THROW_ON_ERROR),
                $acceptShared ? 1 : 0,
                random_bytes(32),
                $this->crypto->encrypt($secrets['test'], self::secretContext($publicId, false)),
                $this->crypto->encrypt($secrets['live'], self::secretContext($publicId, true)),
            ],
        );

        return [$this->findById($id) ?? throw new \RuntimeException('Projet introuvable après création.'), $secrets];
    }

    public function findById(int $id): ?Project
    {
        $row = $this->db->fetchOne('SELECT * FROM projects WHERE id = ?', [$id]);

        return $row === null ? null : Project::fromRow($row);
    }

    public function findByPublicId(string $publicId): ?Project
    {
        $row = $this->db->fetchOne('SELECT * FROM projects WHERE public_id = ?', [$publicId]);

        return $row === null ? null : Project::fromRow($row);
    }

    /** @return list<Project> */
    public function all(): array
    {
        return array_map(Project::fromRow(...), $this->db->fetchAll('SELECT * FROM projects ORDER BY id'));
    }

    /** @param list<string> $allowedOrigins */
    public function updateOrigins(int $id, array $allowedOrigins): void
    {
        $this->db->execute(
            'UPDATE projects SET allowed_origins = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [json_encode(array_values($allowedOrigins), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $id],
        );
    }

    public function signingSecret(Project $project, bool $livemode): string
    {
        return $this->crypto->decrypt(
            $livemode ? $project->signingSecretLiveEnc : $project->signingSecretTestEnc,
            self::secretContext($project->publicId, $livemode),
        );
    }

    /** @return string le nouveau secret en clair (l'ancien cesse immédiatement de valoir) */
    public function rotateSigningSecret(Project $project, bool $livemode): string
    {
        $secret = self::newSecret();
        $column = $livemode ? 'signing_secret_live_enc' : 'signing_secret_test_enc';
        $this->db->execute(
            'UPDATE projects SET ' . $column . ' = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$this->crypto->encrypt($secret, self::secretContext($project->publicId, $livemode)), $project->id],
        );

        return $secret;
    }

    private static function newSecret(): string
    {
        return self::SECRET_PREFIX . Crypto::randomAlnum(40);
    }

    private static function secretContext(string $publicId, bool $livemode): string
    {
        return 'project-signing-secret:' . $publicId . ':' . ($livemode ? 'live' : 'test');
    }
}
