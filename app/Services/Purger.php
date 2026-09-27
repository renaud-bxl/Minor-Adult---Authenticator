<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\ManualReviewRepository;
use App\Models\VerificationRepository;
use App\Models\VerificationSessionRepository;
use App\Models\WebhookDeliveryRepository;

/**
 * Purge planifiée (cron) : minimisation et durées de conservation (RGPD art. 5.1.c et 5.1.e).
 * - revues manuelles sans décision dans le délai → « expired » (la session expire avec elles) ;
 * - sessions de vérification ouvertes dont le délai est dépassé → « expired » ;
 * - sessions terminées au-delà de la rétention (avec leur e-mail chiffré) ;
 * - vérifications expirées ; livraisons de webhooks terminées ; journal d'audit ancien ;
 * - jetons d'e-mail expirés ou utilisés depuis plus d'un jour ;
 * - comptes clients jamais validés après 7 jours (utilisateur non vérifié et compte sans autre membre).
 */
final class Purger
{
    /** @param array{sessions_days: int, deliveries_days: int, audit_days: int, unverified_accounts_days: int} $retention */
    public function __construct(private readonly Database $db, private readonly array $retention)
    {
    }

    public static function fromApplication(Application $app): self
    {
        /** @var array{sessions_days: int, deliveries_days: int, audit_days: int, unverified_accounts_days: int} $retention */
        $retention = $app->config->get('verification.retention');

        return new self($app->db(), $retention);
    }

    /** @return array<string, int> nombre d'éléments traités par catégorie */
    public function run(): array
    {
        $sessions = new VerificationSessionRepository($this->db);

        return [
            // Avant l'expiration des sessions : une revue sans décision dans le délai est close.
            'reviews_expired' => (new ManualReviewRepository($this->db))->expireStale(),
            'sessions_expired' => $sessions->expireStale(),
            'sessions_deleted' => $sessions->purgeOlderThan($this->retention['sessions_days']),
            'verifications_deleted' => (new VerificationRepository($this->db))->purgeExpired(),
            'deliveries_deleted' => (new WebhookDeliveryRepository($this->db))->purgeFinishedOlderThan($this->retention['deliveries_days']),
            'audit_deleted' => (new AuditLog($this->db))->purgeOlderThan($this->retention['audit_days']),
            'tokens_deleted' => $this->db->execute(
                'DELETE FROM user_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY '
                . 'OR (used_at IS NOT NULL AND used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY)',
            ),
            'unverified_accounts_deleted' => $this->purgeUnverifiedAccounts(),
        ];
    }

    private function purgeUnverifiedAccounts(): int
    {
        return $this->db->transaction(function (Database $db): int {
            $users = array_map('intval', array_column($db->fetchAll(
                'SELECT id FROM users WHERE email_verified_at IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY',
                [$this->retention['unverified_accounts_days']],
            ), 'id'));
            if ($users === []) {
                return 0;
            }
            $placeholders = implode(', ', array_fill(0, count($users), '?'));
            // Comptes dont TOUS les membres sont des utilisateurs jamais validés (et donc supprimés).
            $accounts = array_map('intval', array_column($db->fetchAll(
                'SELECT au.account_id FROM account_users au GROUP BY au.account_id '
                . 'HAVING SUM(au.user_id NOT IN (' . $placeholders . ')) = 0',
                $users,
            ), 'account_id'));
            $db->execute('DELETE FROM users WHERE id IN (' . $placeholders . ')', $users);
            if ($accounts !== []) {
                $db->execute('DELETE FROM accounts WHERE id IN (' . implode(', ', array_fill(0, count($accounts), '?')) . ')', $accounts);
            }

            return count($users);
        });
    }
}
