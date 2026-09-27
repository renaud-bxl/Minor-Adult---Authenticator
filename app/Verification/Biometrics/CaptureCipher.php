<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Chiffrement de l'envoi des images, du navigateur à PHP (AES-256-GCM, WebCrypto), EN PLUS de HTTPS.
 *
 * Pourquoi : au-delà de 16 Kio, PHP recopie le corps d'une requête dans un fichier temporaire
 * (upload_tmp_dir) le temps de la requête. Avec ce chiffrement, ce fichier ne contient qu'un chiffré
 * inexploitable : la clé n'est jamais écrite nulle part. Elle est dérivée, pour chaque capture, de la
 * clé applicative (HKDF) et de l'identifiant de capture à usage unique, puis transmise à la page par la
 * réponse (HTTPS) qui ouvre la capture. Données associées (AAD) : session et capture.
 * Format du corps : IV (12 octets) || chiffré || étiquette (16 octets), comme WebCrypto le produit.
 */
final class CaptureCipher
{
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function __construct(private readonly string $masterKey)
    {
        if (strlen($masterKey) < 32) {
            throw new \InvalidArgumentException('Clé de capture trop courte.');
        }
    }

    public function key(string $sessionId, string $captureId): string
    {
        return hash_hmac('sha256', 'capture:' . $sessionId . ':' . $captureId, $this->masterKey, true);
    }

    public static function aad(string $sessionId, string $captureId): string
    {
        return $sessionId . '|' . $captureId;
    }

    /** @throws \InvalidArgumentException chiffré altéré, tronqué ou lié à une autre capture */
    public function decrypt(string $body, string $sessionId, string $captureId): string
    {
        if (strlen($body) < self::IV_BYTES + self::TAG_BYTES + 2) {
            throw new \InvalidArgumentException('capture_invalid');
        }
        $plaintext = openssl_decrypt(
            substr($body, self::IV_BYTES, -self::TAG_BYTES),
            'aes-256-gcm',
            $this->key($sessionId, $captureId),
            OPENSSL_RAW_DATA,
            substr($body, 0, self::IV_BYTES),
            substr($body, -self::TAG_BYTES),
            self::aad($sessionId, $captureId),
        );
        if ($plaintext === false) {
            throw new \InvalidArgumentException('capture_invalid');
        }

        return $plaintext;
    }

    /** Équivalent PHP du chiffrement fait par le navigateur (tests). */
    public function encrypt(string $plaintext, string $sessionId, string $captureId): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key($sessionId, $captureId), OPENSSL_RAW_DATA, $iv, $tag,
            self::aad($sessionId, $captureId), self::TAG_BYTES);

        return $iv . $ciphertext . $tag;
    }
}
