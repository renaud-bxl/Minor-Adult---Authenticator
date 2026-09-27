<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Sessions de vérification. Les transitions d'état sont atomiques (UPDATE … WHERE status = 'pending'
 * et le motif d'étape attendu) : deux requêtes concurrentes ne peuvent pas terminer une session deux
 * fois, ni valider deux fois un même code.
 */
final class VerificationSessionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, scalar|null> $data colonnes à insérer */
    public function create(array $data): VerificationSession
    {
        $columns = array_keys($data);
        $id = $this->db->insert(
            'INSERT INTO verification_sessions (' . implode(', ', $columns) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            array_values($data),
        );

        return $this->findById($id) ?? throw new \RuntimeException('Session introuvable après création.');
    }

    public function findById(int $id): ?VerificationSession
    {
        $row = $this->db->fetchOne('SELECT * FROM verification_sessions WHERE id = ?', [$id]);

        return $row === null ? null : VerificationSession::fromRow($row);
    }

    public function findByPublicId(string $publicId): ?VerificationSession
    {
        $row = $this->db->fetchOne('SELECT * FROM verification_sessions WHERE public_id = ?', [$publicId]);

        return $row === null ? null : VerificationSession::fromRow($row);
    }

    /** Session la plus récente pour une adresse, dans le périmètre (projet, mode). */
    public function latestForEmail(int $projectId, bool $livemode, string $emailHash): ?VerificationSession
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM verification_sessions WHERE project_id = ? AND livemode = ? AND email_hash = ? '
            . 'ORDER BY created_at DESC, id DESC LIMIT 1',
            [$projectId, $livemode ? 1 : 0, $emailHash],
        );

        return $row === null ? null : VerificationSession::fromRow($row);
    }

    public function recordConsent(int $id): bool
    {
        return $this->db->execute(
            "UPDATE verification_sessions SET consent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND status = 'pending' AND consent_at IS NULL AND expires_at > UTC_TIMESTAMP()",
            [$id],
        ) === 1;
    }

    /** Enregistre un nouveau code (haché) ; refusé si le quota d'envois est atteint ou trop rapproché. */
    public function storeCode(int $id, string $codeHash, int $ttl, int $maxSends, int $minInterval): bool
    {
        return $this->db->execute(
            "UPDATE verification_sessions SET code_hash = ?, code_expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, "
            . "code_sends = code_sends + 1, code_sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND status = 'pending' AND consent_at IS NOT NULL AND email_verified_at IS NULL "
            . "AND expires_at > UTC_TIMESTAMP() AND code_sends < ? "
            . "AND (code_sent_at IS NULL OR code_sent_at <= UTC_TIMESTAMP() - INTERVAL ? SECOND)",
            [$codeHash, $ttl, $id, $maxSends, $minInterval],
        ) === 1;
    }

    /**
     * Tentative de code : compte l'essai et valide l'adresse si le code est bon, en une seule écriture
     * atomique. Renvoie true si le code a été accepté.
     */
    public function attemptCode(int $id, string $codeHash, int $maxAttempts): bool
    {
        $accepted = $this->db->execute(
            "UPDATE verification_sessions SET email_verified_at = UTC_TIMESTAMP(), code_hash = NULL, "
            . "code_attempts = code_attempts + 1, updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND status = 'pending' AND email_verified_at IS NULL AND code_hash = ? "
            . "AND code_expires_at > UTC_TIMESTAMP() AND code_attempts < ? AND expires_at > UTC_TIMESTAMP()",
            [$id, $codeHash, $maxAttempts],
        );
        if ($accepted === 1) {
            return true;
        }
        $this->db->execute(
            "UPDATE verification_sessions SET code_attempts = code_attempts + 1, updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND status = 'pending' AND email_verified_at IS NULL AND code_attempts < ?",
            [$id, $maxAttempts],
        );

        return false;
    }

    public function setShareOptIn(int $id, bool $optIn): void
    {
        $this->db->execute(
            "UPDATE verification_sessions SET share_opt_in = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'",
            [$optIn ? 1 : 0, $id],
        );
    }

    /**
     * Termine une session encore ouverte avec un résultat. Renvoie false si elle ne l'était plus
     * (déjà terminée par une requête concurrente, échouée ou expirée).
     */
    public function complete(
        int $id,
        string $method,
        string $reuse,
        bool $isAdult,
        \DateTimeImmutable $verifiedAt,
        \DateTimeImmutable $expiresAt,
        bool $requireEmailVerified,
    ): bool {
        return $this->db->execute(
            "UPDATE verification_sessions SET status = 'completed', method = ?, reuse = ?, result_is_adult = ?, "
            . "result_verified_at = ?, result_expires_at = ?, completed_at = UTC_TIMESTAMP(), code_hash = NULL, "
            . "updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending' AND expires_at > UTC_TIMESTAMP()"
            . ($requireEmailVerified ? ' AND email_verified_at IS NOT NULL AND consent_at IS NOT NULL' : ''),
            [$method, $reuse, $isAdult ? 1 : 0, self::sql($verifiedAt), self::sql($expiresAt), $id],
        ) === 1;
    }

    public function fail(int $id, string $reason, ?string $method = null): bool
    {
        return $this->db->execute(
            "UPDATE verification_sessions SET status = 'failed', failure_reason = ?, method = COALESCE(?, method), "
            . "completed_at = UTC_TIMESTAMP(), code_hash = NULL, updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND status = 'pending'",
            [$reason, $method, $id],
        ) === 1;
    }

    /** @return list<string> identifiants publics des sessions d'une adresse dans le périmètre */
    public function publicIdsForEmail(int $projectId, bool $livemode, string $emailHash): array
    {
        return array_column($this->db->fetchAll(
            'SELECT public_id FROM verification_sessions WHERE project_id = ? AND livemode = ? AND email_hash = ?',
            [$projectId, $livemode ? 1 : 0, $emailHash],
        ), 'public_id');
    }

    /** Efface toutes les sessions d'une adresse dans le périmètre (droit à l'effacement). */
    public function deleteForEmail(int $projectId, bool $livemode, string $emailHash): int
    {
        return $this->db->execute(
            'DELETE FROM verification_sessions WHERE project_id = ? AND livemode = ? AND email_hash = ?',
            [$projectId, $livemode ? 1 : 0, $emailHash],
        );
    }

    /** Cron : sessions ouvertes dont le délai est dépassé. */
    public function expireStale(): int
    {
        return $this->db->execute(
            "UPDATE verification_sessions SET status = 'expired', code_hash = NULL, updated_at = UTC_TIMESTAMP() "
            . "WHERE status = 'pending' AND expires_at <= UTC_TIMESTAMP()",
        );
    }

    /** Cron : suppression des sessions (et de leur e-mail chiffré) au-delà de la rétention. */
    public function purgeOlderThan(int $days): int
    {
        return $this->db->execute(
            "DELETE FROM verification_sessions WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY AND status <> 'pending'",
            [$days],
        );
    }

    public static function sql(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
