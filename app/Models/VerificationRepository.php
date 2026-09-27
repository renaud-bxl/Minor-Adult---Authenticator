<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Résultats de vérification (table `verifications`), un par (projet, mode, hash d'e-mail).
 * Toute lecture est cloisonnée par projet et par mode, sauf la recherche d'une preuve partagée
 * (shared_hash), qui n'existe que si l'utilisateur l'a expressément autorisée.
 */
final class VerificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null vérification non expirée */
    public function findValid(int $projectId, bool $livemode, string $emailHash): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM verifications WHERE project_id = ? AND livemode = ? AND email_hash = ? AND expires_at > UTC_TIMESTAMP()',
            [$projectId, $livemode ? 1 : 0, $emailHash],
        );
    }

    /**
     * Preuve réutilisable d'un AUTRE projet, non expirée, dans le même mode.
     *
     * @return array<string, mixed>|null
     */
    public function findShared(string $sharedHash, bool $livemode, int $excludeProjectId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM verifications WHERE shared_hash = ? AND livemode = ? AND project_id <> ? AND source = 'verification' "
            . 'AND expires_at > UTC_TIMESTAMP() ORDER BY verified_at DESC LIMIT 1',
            [$sharedHash, $livemode ? 1 : 0, $excludeProjectId],
        );
    }

    /** Enregistre (ou remplace) le résultat d'une adresse pour un projet et un mode. */
    public function upsert(
        int $projectId,
        bool $livemode,
        string $emailHash,
        string $emailEnc,
        bool $isAdult,
        int $minAge,
        string $method,
        string $source,
        ?string $sharedHash,
        \DateTimeImmutable $verifiedAt,
        \DateTimeImmutable $expiresAt,
    ): void {
        $this->db->execute(
            'INSERT INTO verifications (project_id, livemode, email_hash, email_enc, is_adult, min_age, method, source, shared_hash, '
            . 'verified_at, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE email_enc = VALUES(email_enc), is_adult = VALUES(is_adult), min_age = VALUES(min_age), '
            . 'method = VALUES(method), source = VALUES(source), shared_hash = VALUES(shared_hash), verified_at = VALUES(verified_at), '
            . 'expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()',
            [
                $projectId, $livemode ? 1 : 0, $emailHash, $emailEnc, $isAdult ? 1 : 0, $minAge, $method, $source, $sharedHash,
                VerificationSessionRepository::sql($verifiedAt), VerificationSessionRepository::sql($expiresAt),
            ],
        );
    }

    public function deleteForEmail(int $projectId, bool $livemode, string $emailHash): int
    {
        return $this->db->execute(
            'DELETE FROM verifications WHERE project_id = ? AND livemode = ? AND email_hash = ?',
            [$projectId, $livemode ? 1 : 0, $emailHash],
        );
    }

    /** Cron : les vérifications expirées ne servent plus à rien (minimisation). */
    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM verifications WHERE expires_at <= UTC_TIMESTAMP()');
    }
}
