<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Models\ProjectRepository;
use App\Verification\VerificationService;

/**
 * Rotation de CRYPTO_KEY : réécrit avec la clé courante tout chiffré en base produit avec une ancienne
 * clé du trousseau (octet de version). Procédure (docs/api-tests.md, « Rotation de CRYPTO_KEY ») :
 * 1. nouvelle clé dans CRYPTO_KEY, version incrémentée dans CRYPTO_KEY_VERSION, ancienne clé ajoutée à
 *    CRYPTO_PREVIOUS_KEYS ; redémarrage (web et workers) ;
 * 2. php bin/reencrypt.php (idempotent, reprend où il s'est arrêté) ;
 * 3. une fois la file d'e-mails Redis vidée (travaux différés de quelques minutes), retrait de
 *    l'ancienne clé de CRYPTO_PREVIOUS_KEYS.
 *
 * Chaque ligne est réécrite par un UPDATE conditionné à l'ancienne valeur : une écriture concurrente
 * de l'application n'est jamais écrasée (la ligne est simplement ignorée, déjà à jour).
 */
final class KeyRotation
{
    private const BATCH = 500;

    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    /** @return array<string, int> chiffrés réécrits par colonne */
    public function run(): array
    {
        $counts = [];
        foreach (['test' => false, 'live' => true] as $mode => $livemode) {
            $counts["projects.signing_secret_{$mode}_enc"] = $this->rewrite('projects', "signing_secret_{$mode}_enc", 'public_id',
                static fn (array $row): string => ProjectRepository::secretContext((string) $row['public_id'], $livemode));
            $counts["projects.previous_secret_{$mode}_enc"] = $this->rewrite('projects', "previous_secret_{$mode}_enc", 'public_id',
                static fn (array $row): string => ProjectRepository::previousSecretContext((string) $row['public_id'], $livemode));
        }
        $counts['verifications.email_enc'] = $this->rewrite('verifications', 'email_enc', 'project_id, livemode, email_hash',
            static fn (array $row): string => VerificationService::verificationEmailContext((int) $row['project_id'], (bool) $row['livemode'], (string) $row['email_hash']));
        $counts['verification_sessions.email_enc'] = $this->rewrite('verification_sessions', 'email_enc', 'public_id',
            static fn (array $row): string => VerificationService::sessionEmailContext((string) $row['public_id']));
        $counts['webhook_deliveries.payload_enc'] = $this->rewrite('webhook_deliveries', 'payload_enc', 'event_id',
            static fn (array $row): string => 'webhook:' . $row['event_id']);

        return $counts;
    }

    /** @param \Closure(array<string, mixed>): string $context données associées (AAD) de la ligne */
    private function rewrite(string $table, string $column, string $contextColumns, \Closure $context): int
    {
        $rewritten = 0;
        $lastId = 0;
        do {
            $rows = $this->db->fetchAll(
                "SELECT id, {$column} AS payload, {$contextColumns} FROM {$table} WHERE id > ? AND {$column} IS NOT NULL ORDER BY id LIMIT " . self::BATCH,
                [$lastId],
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $payload = (string) $row['payload'];
                if (!$this->crypto->needsReencryption($payload)) {
                    continue;
                }
                $aad = $context($row);
                $fresh = $this->crypto->encrypt($this->crypto->decrypt($payload, $aad), $aad);
                $rewritten += $this->db->execute("UPDATE {$table} SET {$column} = ? WHERE id = ? AND {$column} = ?", [$fresh, $lastId, $payload]);
            }
        } while (count($rows) === self::BATCH);

        return $rewritten;
    }
}
