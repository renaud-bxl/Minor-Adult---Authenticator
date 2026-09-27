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
     * Preuve réutilisable d'un AUTRE projet, non expirée, dans le même mode. $accountId restreint la
     * recherche aux projets d'un même compte client (sandbox : les codes y sont affichés à l'écran, le
     * contrôle de l'adresse n'y prouve rien ; un client ne doit pas sonder les tests d'un autre client).
     *
     * @return array<string, mixed>|null
     */
    public function findShared(string $sharedHash, bool $livemode, int $excludeProjectId, ?int $accountId = null, ?int $minAge = null): ?array
    {
        // Âge demandé filtré en SQL (même règle que VerificationService::covers) : une preuve plus
        // ancienne mais pertinente n'est pas masquée par une plus récente qui ne l'est pas.
        return $this->db->fetchOne(
            "SELECT v.* FROM verifications v JOIN projects p ON p.id = v.project_id WHERE v.shared_hash = ? AND v.livemode = ? "
            . "AND v.project_id <> ? AND v.source = 'verification' AND v.expires_at > UTC_TIMESTAMP() "
            . ($accountId !== null ? 'AND p.account_id = ? ' : '')
            . ($minAge !== null ? 'AND ((v.is_adult = 1 AND v.min_age >= ?) OR (v.is_adult = 0 AND v.min_age <= ?)) ' : '')
            . 'ORDER BY v.verified_at DESC LIMIT 1',
            [$sharedHash, $livemode ? 1 : 0, $excludeProjectId, ...($accountId !== null ? [$accountId] : []), ...($minAge !== null ? [$minAge, $minAge] : [])],
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
