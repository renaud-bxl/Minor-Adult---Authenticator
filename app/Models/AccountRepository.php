<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Comptes clients (entreprises) et rattachement de leurs utilisateurs. */
final class AccountRepository
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_ACCOUNTANT = 'accountant';

    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $name, string $locale): int
    {
        return $this->db->insert(
            'INSERT INTO accounts (name, locale, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$name, $locale],
        );
    }

    public function addMember(int $accountId, int $userId, string $role): void
    {
        if (!in_array($role, [self::ROLE_OWNER, self::ROLE_DEVELOPER, self::ROLE_ACCOUNTANT], true)) {
            throw new \InvalidArgumentException('Rôle inconnu : ' . $role);
        }
        $this->db->execute(
            'INSERT INTO account_users (account_id, user_id, role, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$accountId, $userId, $role],
        );
    }

    /**
     * Compte principal d'un utilisateur (le plus ancien rattachement ; le multi-comptes arrive en phase 5).
     *
     * @return array<string, mixed>|null colonnes du compte + « role »
     */
    public function findPrimaryForUser(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, au.role FROM accounts a JOIN account_users au ON au.account_id = a.id '
            . 'WHERE au.user_id = ? ORDER BY au.created_at, a.id LIMIT 1',
            [$userId],
        );
    }

    public function updateLocaleForUser(int $userId, string $locale): void
    {
        $account = $this->findPrimaryForUser($userId);
        if ($account !== null) {
            $this->db->execute('UPDATE accounts SET locale = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$locale, (int) $account['id']]);
        }
    }
}
