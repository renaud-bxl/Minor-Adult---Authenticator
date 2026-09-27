<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\IpAddress;

/**
 * Journal d'audit : identifiants internes, action, date, IP tronquée et métadonnées techniques.
 * Aucune donnée d'identité : ni e-mail, ni hash d'e-mail, ni IP complète, ni résultat nominatif.
 */
final class AuditLog
{
    /** Métadonnées admises (liste blanche : rien d'autre n'est jamais écrit). */
    private const METADATA_KEYS = ['session', 'livemode', 'method', 'result', 'reason', 'key', 'reuse', 'count', 'event'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, scalar|null> $metadata */
    public function record(
        string $action,
        ?int $userId = null,
        ?int $accountId = null,
        ?string $ip = null,
        ?int $projectId = null,
        array $metadata = [],
    ): void {
        $metadata = array_intersect_key($metadata, array_flip(self::METADATA_KEYS));
        $this->db->execute(
            'INSERT INTO audit_log (account_id, user_id, project_id, action, ip_truncated, metadata, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $accountId,
                $userId,
                $projectId,
                $action,
                $ip === null ? null : IpAddress::truncate($ip),
                $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    /** Cron : rétention du journal. */
    public function purgeOlderThan(int $days): int
    {
        return $this->db->execute('DELETE FROM audit_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', [$days]);
    }
}
