<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Primitives cryptographiques de l'application.
 *
 * - Chiffrement AES-256-GCM (e-mails chiffrés) : format binaire version(1) || nonce(12) || tag(16) || texte chiffré.
 *   Les données associées (AAD) lient un chiffré à son contexte (ex. l'identifiant du client).
 * - Hash d'e-mail : HMAC-SHA256 sur l'e-mail normalisé, salé par client et poivré par une clé serveur
 *   (une fuite de la base seule ne permet pas de retrouver les e-mails par force brute).
 * - Jetons à usage unique : aléatoires (base64url), stockés uniquement sous forme de SHA-256.
 */
final class Crypto
{
    private const VERSION = "\x01";
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;

    public function __construct(private readonly string $encryptionKey, private readonly string $hashKey)
    {
        if (strlen($encryptionKey) !== self::KEY_BYTES || strlen($hashKey) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('Les clés doivent faire exactement 32 octets.');
        }
    }

    /** Décode une clé « base64:… » ou base64 brute issue du .env. */
    public static function decodeKey(string $encoded): string
    {
        $raw = base64_decode(preg_replace('/^base64:/', '', trim($encoded)) ?? '', true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('Clé invalide : 32 octets encodés en base64 attendus (php bin/generate-keys.php).');
        }

        return $raw;
    }

    public function encrypt(string $plaintext, string $associatedData = ''): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Échec du chiffrement.');
        }

        return self::VERSION . $nonce . $tag . $ciphertext;
    }

    /** @throws \RuntimeException si le chiffré est altéré, tronqué ou lié à d'autres données associées */
    public function decrypt(string $payload, string $associatedData = ''): string
    {
        $headerLength = 1 + self::NONCE_BYTES + self::TAG_BYTES;
        if (strlen($payload) < $headerLength || $payload[0] !== self::VERSION) {
            throw new \RuntimeException('Chiffré invalide.');
        }
        $nonce = substr($payload, 1, self::NONCE_BYTES);
        $tag = substr($payload, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $plaintext = openssl_decrypt(substr($payload, $headerLength), self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        if ($plaintext === false) {
            throw new \RuntimeException('Chiffré invalide.');
        }

        return $plaintext;
    }

    /** Hash stable (hex, 64 caractères) d'un e-mail pour un sel donné. */
    public function hashEmail(string $email, string $salt): string
    {
        return hash_hmac('sha256', $salt . "\0" . self::normalizeEmail($email), $this->hashKey);
    }

    /** Empreinte opaque d'un identifiant (IP, e-mail) pour les clés Redis : jamais de valeur en clair. */
    public function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        if (class_exists(\Normalizer::class)) {
            $email = \Normalizer::normalize($email, \Normalizer::FORM_C) ?: $email;
        }

        return mb_strtolower($email, 'UTF-8');
    }

    /**
     * Chaîne aléatoire alphanumérique (base 62, tirage uniforme) : identifiants publics et clés API,
     * sans caractère ambigu dans une URL ou un en-tête. 40 caractères ≈ 238 bits d'entropie.
     */
    public static function randomAlnum(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }

        return $out;
    }

    /** Code numérique aléatoire à $digits chiffres (zéros de tête conservés). */
    public static function randomDigits(int $digits): string
    {
        return str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Sous-clé dérivée de la clé applicative pour un usage donné (HKDF-SHA256) : une clé par usage
     * (jetons de page, etc.), sans jamais réutiliser la clé brute.
     */
    public function deriveKey(string $purpose): string
    {
        return hash_hkdf('sha256', $this->hashKey, 32, 'veriage:' . $purpose);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** SHA-256 binaire (32 octets) d'un jeton, seule forme stockée en base. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token, true);
    }
}
