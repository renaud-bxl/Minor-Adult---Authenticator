<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Livraisons de webhooks : file durable en base. Réservation atomique par lot (lock_token) pour
 * permettre plusieurs workers sans double envoi ; une réservation abandonnée (worker arrêté) expire
 * et la livraison redevient disponible.
 */
final class WebhookDeliveryRepository
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return bool false si l'événement était déjà en file pour cet endpoint (idempotence) */
    public function enqueue(string $eventId, string $eventType, int $endpointId, int $projectId, string $payloadEnc, ?string $sessionPublicId = null): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO webhook_deliveries (event_id, event_type, endpoint_id, project_id, session_public_id, payload_enc, status, '
            . "attempts, next_attempt_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$eventId, $eventType, $endpointId, $projectId, $sessionPublicId, $payloadEnc],
        ) === 1;
    }

    /**
     * Droit à l'effacement : livraisons (et leur corps chiffré contenant l'adresse) liées à ces sessions.
     *
     * @param list<string> $sessionPublicIds
     */
    public function deleteForSessions(int $projectId, array $sessionPublicIds): int
    {
        if ($sessionPublicIds === []) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM webhook_deliveries WHERE project_id = ? AND session_public_id IN ('
            . implode(', ', array_fill(0, count($sessionPublicIds), '?')) . ')',
            [$projectId, ...$sessionPublicIds],
        );
    }

    /**
     * Réserve jusqu'à $limit livraisons échues pour $lockSeconds secondes. Chaque ligne renvoyée porte
     * son « lock_token » : le worker doit prolonger la réservation (renewLock) juste avant chaque envoi,
     * et ses mises à jour ne s'appliquent que s'il détient encore la réservation.
     *
     * @return list<array<string, mixed>>
     */
    public function claimDue(int $limit, int $lockSeconds): array
    {
        $token = random_bytes(16);
        $this->db->execute(
            "UPDATE webhook_deliveries SET lock_token = ?, locked_until = UTC_TIMESTAMP() + INTERVAL ? SECOND "
            . "WHERE status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP() "
            . "AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP()) ORDER BY next_attempt_at, id LIMIT " . max(1, $limit),
            [$token, $lockSeconds],
        );

        return $this->db->fetchAll("SELECT * FROM webhook_deliveries WHERE lock_token = ? AND status = 'pending' ORDER BY id", [$token]);
    }

    /**
     * Prolonge la réservation d'une livraison juste avant son envoi, sous un NOUVEAU jeton (l'écriture
     * change toujours la ligne : MariaDB ne compte que les lignes réellement modifiées). Renvoie null si
     * la réservation a expiré et a été reprise par un autre worker (lot trop long) : ne pas envoyer.
     */
    public function renewLock(int $id, string $lockToken, int $lockSeconds): ?string
    {
        $renewed = random_bytes(16);
        $updated = $this->db->execute(
            "UPDATE webhook_deliveries SET lock_token = ?, locked_until = UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_at = UTC_TIMESTAMP() "
            . "WHERE id = ? AND lock_token = ? AND status = 'pending'",
            [$renewed, $lockSeconds, $id, $lockToken],
        );

        return $updated === 1 ? $renewed : null;
    }

    public function markDelivered(int $id, string $lockToken, int $statusCode): void
    {
        $this->db->execute(
            "UPDATE webhook_deliveries SET status = 'delivered', attempts = attempts + 1, last_status_code = ?, last_error = NULL, "
            . 'delivered_at = UTC_TIMESTAMP(), lock_token = NULL, locked_until = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND lock_token = ?',
            [$statusCode, $id, $lockToken],
        );
    }

    /** Échec d'une tentative : nouvelle tentative après $delaySeconds, ou abandon si $final (réservation détenue). */
    public function markAttemptFailed(int $id, string $lockToken, ?int $statusCode, string $error, int $delaySeconds, bool $final): void
    {
        $this->db->execute(
            'UPDATE webhook_deliveries SET status = ?, attempts = attempts + 1, last_status_code = ?, last_error = ?, '
            . 'next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, lock_token = NULL, locked_until = NULL, '
            . 'updated_at = UTC_TIMESTAMP() WHERE id = ? AND lock_token = ?',
            [$final ? self::FAILED : self::PENDING, $statusCode, substr($error, 0, 64), $delaySeconds, $id, $lockToken],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByEvent(int $endpointId, string $eventId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM webhook_deliveries WHERE endpoint_id = ? AND event_id = ?', [$endpointId, $eventId]);
    }

    /** Cron : livraisons terminées (livrées ou abandonnées) au-delà de la rétention. */
    public function purgeFinishedOlderThan(int $days): int
    {
        return $this->db->execute(
            "DELETE FROM webhook_deliveries WHERE status <> 'pending' AND updated_at < UTC_TIMESTAMP() - INTERVAL ? DAY",
            [$days],
        );
    }
}
