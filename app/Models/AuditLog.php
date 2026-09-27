<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\IpAddress;

/** Journal d'audit : identifiants internes, action, date et IP tronquée. Aucune donnée d'identité. */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(string $action, ?int $userId = null, ?int $accountId = null, ?string $ip = null): void
    {
        $this->db->execute(
            'INSERT INTO audit_log (account_id, user_id, action, ip_truncated, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$accountId, $userId, $action, $ip === null ? null : IpAddress::truncate($ip)],
        );
    }
}
