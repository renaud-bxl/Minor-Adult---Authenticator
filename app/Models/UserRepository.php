<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

/** Utilisateurs de l'espace client. Les e-mails sont stockés normalisés (minuscules, NFC). */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE email = ?', [Crypto::normalizeEmail($email)]);
    }

    public function create(string $email, string $passwordHash): int
    {
        return $this->db->insert(
            'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [Crypto::normalizeEmail($email), $passwordHash],
        );
    }

    public function markEmailVerified(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL',
            [$id],
        );
    }

    /** Nouveau mot de passe : incrémente auth_version, ce qui invalide toutes les sessions existantes. */
    public function changePassword(int $id, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ?, auth_version = auth_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$passwordHash, $id],
        );
    }

    /** Mise à niveau transparente du hash (paramètres Argon2id modifiés), sans invalider les sessions. */
    public function rehashPassword(int $id, string $passwordHash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$passwordHash, $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->db->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }
}
