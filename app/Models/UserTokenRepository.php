<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Jetons à usage unique envoyés par e-mail. Seul leur SHA-256 est stocké. Un seul jeton actif par
 * utilisateur et par type : en émettre un nouveau révoque les précédents.
 */
final class UserTokenRepository
{
    public const EMAIL_VERIFICATION = 'email_verification';
    public const PASSWORD_RESET = 'password_reset';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string le jeton en clair, à transmettre uniquement dans l'e-mail */
    public function issue(int $userId, string $type, int $ttlSeconds): string
    {
        $token = Crypto::randomToken();
        $this->db->transaction(function (Database $db) use ($userId, $type, $ttlSeconds, $token): void {
            $this->revokeAll($userId, $type);
            $db->execute(
                'INSERT INTO user_tokens (user_id, type, token_hash, expires_at, created_at) '
                . 'VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, UTC_TIMESTAMP())',
                [$userId, $type, Crypto::hashToken($token), $ttlSeconds],
            );
        });

        return $token;
    }

    /** Identifiant de l'utilisateur si le jeton est valide (non utilisé, non expiré), sans le consommer. */
    public function peek(string $type, string $token): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT user_id FROM user_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [Crypto::hashToken($token), $type],
        );

        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Consomme le jeton de façon atomique : deux requêtes concurrentes ne peuvent pas l'utiliser
     * toutes les deux (seul l'UPDATE qui passe used_at de NULL à une date réussit).
     */
    public function consume(string $type, string $token): ?int
    {
        $hash = Crypto::hashToken($token);
        $updated = $this->db->execute(
            'UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$hash, $type],
        );
        if ($updated !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT user_id FROM user_tokens WHERE token_hash = ?', [$hash]);

        return $row === null ? null : (int) $row['user_id'];
    }

    public function revokeAll(int $userId, string $type): void
    {
        $this->db->execute('DELETE FROM user_tokens WHERE user_id = ? AND type = ? AND used_at IS NULL', [$userId, $type]);
    }
}
