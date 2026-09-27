<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * File de vérification manuelle (table `manual_reviews`). Aucune donnée d'identité ni image : score de
 * correspondance, contrôle du vivant, motifs codés et résultat d'âge provisoire (booléen). Décision
 * atomique (une seule fois, tant que la revue est « pending »).
 */
final class ManualReviewRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param list<string> $reasons */
    public function create(int $sessionId, int $projectId, bool $livemode, string $method, ?float $score, bool $liveness, array $reasons, bool $provisionalIsAdult): int
    {
        return $this->db->insert(
            'INSERT INTO manual_reviews (session_id, project_id, livemode, method, face_match_score, liveness_passed, reasons, '
            . 'provisional_is_adult, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', UTC_TIMESTAMP())',
            [$sessionId, $projectId, $livemode ? 1 : 0, $method, $score === null ? null : round($score, 4), $liveness ? 1 : 0,
                json_encode(array_values($reasons), JSON_THROW_ON_ERROR), $provisionalIsAdult ? 1 : 0],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM manual_reviews WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> revues en attente, les plus anciennes d'abord */
    public function pending(int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, s.public_id, s.expires_at, s.min_age FROM manual_reviews r '
            . 'JOIN verification_sessions s ON s.id = r.session_id '
            . "WHERE r.status = 'pending' ORDER BY r.created_at, r.id LIMIT " . max(1, min(1000, $limit)),
        );
    }

    /** Décision (approved | rejected | expired), seulement si la revue est encore en attente. */
    public function decide(int $id, string $status): bool
    {
        if (!in_array($status, ['approved', 'rejected', 'expired'], true)) {
            throw new \InvalidArgumentException('Décision invalide.');
        }

        return $this->db->execute(
            "UPDATE manual_reviews SET status = ?, decided_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'",
            [$status, $id],
        ) === 1;
    }

    /** Cron : revues dont la session a expiré sans décision. */
    public function expireStale(): int
    {
        return $this->db->execute(
            "UPDATE manual_reviews r JOIN verification_sessions s ON s.id = r.session_id "
            . "SET r.status = 'expired', r.decided_at = UTC_TIMESTAMP() "
            . "WHERE r.status = 'pending' AND (s.status <> 'pending' OR s.expires_at <= UTC_TIMESTAMP())",
        );
    }
}
