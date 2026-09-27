<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Clés API secrètes « sk_live_… » (production) et « sk_test_… » (sandbox).
 *
 * Seul le SHA-256 de la clé est stocké : 40 caractères base 62 (≈ 238 bits) rendent toute recherche
 * exhaustive impossible, un hachage lent serait inutile et coûteux à chaque appel d'API. La clé en
 * clair n'est affichée qu'une fois, à sa création.
 */
final class ApiKeyRepository
{
    public const PATTERN = '/^sk_(test|live)_[A-Za-z0-9]{40}$/D';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string la clé en clair */
    public function create(int $projectId, bool $livemode): string
    {
        $key = 'sk_' . ($livemode ? 'live' : 'test') . '_' . Crypto::randomAlnum(40);
        $this->db->insert(
            'INSERT INTO api_keys (project_id, livemode, key_hash, last4, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$projectId, $livemode ? 1 : 0, Crypto::hashToken($key), substr($key, -4)],
        );

        return $key;
    }

    /**
     * Clé active correspondant au secret présenté, avec son projet.
     *
     * @return array{key_id: int, livemode: bool, last4: string, project: Project}|null
     */
    public function authenticate(string $key): ?array
    {
        if (preg_match(self::PATTERN, $key, $m) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT k.id AS key_id, k.livemode AS key_livemode, k.last4 AS key_last4, p.* FROM api_keys k '
            . 'JOIN projects p ON p.id = k.project_id WHERE k.key_hash = ? AND k.revoked_at IS NULL',
            [Crypto::hashToken($key)],
        );
        // Défense en profondeur : le préfixe doit correspondre au mode enregistré.
        if ($row === null || (bool) $row['key_livemode'] !== ($m[1] === 'live')) {
            return null;
        }

        return [
            'key_id' => (int) $row['key_id'],
            'livemode' => (bool) $row['key_livemode'],
            'last4' => (string) $row['key_last4'],
            'project' => Project::fromRow($row),
        ];
    }

    /** Horodatage d'usage, au plus une écriture par minute et par clé. */
    public function touch(int $keyId): void
    {
        $this->db->execute(
            'UPDATE api_keys SET last_used_at = UTC_TIMESTAMP() WHERE id = ? '
            . 'AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 1 MINUTE)',
            [$keyId],
        );
    }

    /** Révoque toutes les clés actives d'un projet pour un mode. */
    public function revokeAll(int $projectId, bool $livemode): int
    {
        return $this->db->execute(
            'UPDATE api_keys SET revoked_at = UTC_TIMESTAMP() WHERE project_id = ? AND livemode = ? AND revoked_at IS NULL',
            [$projectId, $livemode ? 1 : 0],
        );
    }

    /** @return list<array<string, mixed>> clés du projet (sans hash) */
    public function listForProject(int $projectId): array
    {
        return $this->db->fetchAll(
            'SELECT id, livemode, last4, last_used_at, revoked_at, created_at FROM api_keys WHERE project_id = ? ORDER BY id',
            [$projectId],
        );
    }
}
