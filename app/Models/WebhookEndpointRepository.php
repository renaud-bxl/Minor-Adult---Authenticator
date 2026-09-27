<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

/** URL de webhook des projets (par mode). La validation SSRF est faite par l'appelant (UrlGuard). */
final class WebhookEndpointRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $projectId, bool $livemode, string $url): string
    {
        $publicId = 'we_' . Crypto::randomAlnum(20);
        $this->db->insert(
            'INSERT INTO webhook_endpoints (public_id, project_id, livemode, url, enabled, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$publicId, $projectId, $livemode ? 1 : 0, $url],
        );

        return $publicId;
    }

    /** @return list<array<string, mixed>> */
    public function enabledFor(int $projectId, bool $livemode): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM webhook_endpoints WHERE project_id = ? AND livemode = ? AND enabled = 1 ORDER BY id',
            [$projectId, $livemode ? 1 : 0],
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM webhook_endpoints WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listForProject(int $projectId): array
    {
        return $this->db->fetchAll('SELECT * FROM webhook_endpoints WHERE project_id = ? ORDER BY id', [$projectId]);
    }

    public function disableAll(int $projectId, bool $livemode): void
    {
        $this->db->execute(
            'UPDATE webhook_endpoints SET enabled = 0, updated_at = UTC_TIMESTAMP() WHERE project_id = ? AND livemode = ?',
            [$projectId, $livemode ? 1 : 0],
        );
    }
}
