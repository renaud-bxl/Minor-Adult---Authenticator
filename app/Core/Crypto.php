<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Primitives cryptographiques de l'application.
 *
 * - Chiffrement AES-256-GCM (e-mails chiffrés) : format binaire version(1) || nonce(12) || tag(16) || texte chiffré.
 *   Les données associées (AAD) lient un chiffré à son contexte (ex. l'identifiant du client).
 *   L'octet de version désigne la clé de chiffrement (trousseau) : rotation de CRYPTO_KEY sans perte.
 *   On chiffre toujours avec la clé courante (CRYPTO_KEY, version CRYPTO_KEY_VERSION) ; les anciennes
 *   (CRYPTO_PREVIOUS_KEYS) ne servent qu'à déchiffrer, le temps que bin/reencrypt.php réécrive tout.
 * - Hash d'e-mail : HMAC-SHA256 sur l'e-mail normalisé, salé par client et poivré par une clé serveur
 *   (une fuite de la base seule ne permet pas de retrouver les e-mails par force brute).
 * - Jetons à usage unique : aléatoires (base64url), stockés uniquement sous forme de SHA-256.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;

    /** @var array<int, string> version => clé de chiffrement (courante et anciennes) */
    private readonly array $keyring;

    /**
     * @param int                $keyVersion   version de la clé courante (1 à 255), écrite dans chaque chiffré
     * @param array<int, string> $previousKeys anciennes clés (version => clé brute), pour déchiffrer seulement
     */
    public function __construct(
        private readonly string $encryptionKey,
        private readonly string $hashKey,
        private readonly int $keyVersion = 1,
        array $previousKeys = [],
    ) {
        if (strlen($encryptionKey) !== self::KEY_BYTES || strlen($hashKey) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('Les clés doivent faire exactement 32 octets.');
        }
        if ($keyVersion < 1 || $keyVersion > 255) {
            throw new \InvalidArgumentException('Version de clé invalide (1 à 255).');
        }
        foreach ($previousKeys as $version => $key) {
            if ($version < 1 || $version > 255 || $version === $keyVersion || strlen($key) !== self::KEY_BYTES) {
                throw new \InvalidArgumentException('Ancienne clé invalide (version ' . $version . ').');
            }
        }
        $this->keyring = [$keyVersion => $encryptionKey] + $previousKeys;
    }

    /**
     * Trousseau d'anciennes clés depuis le .env : « 1:base64:…,2:base64:… » (version:clé).
     *
     * @return array<int, string>
     * @throws \InvalidArgumentException format ou clé invalide
     */
    public static function parseKeyring(string $value): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $entry) {
            if (preg_match('/^(\d{1,3}):(.+)$/sD', $entry, $m) !== 1 || isset($keys[(int) $m[1]])) {
                throw new \InvalidArgumentException('CRYPTO_PREVIOUS_KEYS : entrée invalide ou version en double (format « version:base64:… »).');
            }
            $keys[(int) $m[1]] = self::decodeKey($m[2]);
        }

        return $keys;
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

        return chr($this->keyVersion) . $nonce . $tag . $ciphertext;
    }

    /** @throws \RuntimeException si le chiffré est altéré, tronqué ou lié à d'autres données associées */
    public function decrypt(string $payload, string $associatedData = ''): string
    {
        $headerLength = 1 + self::NONCE_BYTES + self::TAG_BYTES;
        $key = strlen($payload) >= $headerLength ? ($this->keyring[ord($payload[0])] ?? null) : null;
        if ($key === null) {
            throw new \RuntimeException('Chiffré invalide.');
        }
        $nonce = substr($payload, 1, self::NONCE_BYTES);
        $tag = substr($payload, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $plaintext = openssl_decrypt(substr($payload, $headerLength), self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        if ($plaintext === false) {
            throw new \RuntimeException('Chiffré invalide.');
        }

        return $plaintext;
    }

    /** Le chiffré a-t-il été produit avec une autre clé que la clé courante (à réécrire après rotation) ? */
    public function needsReencryption(string $payload): bool
    {
        return $payload === '' || ord($payload[0]) !== $this->keyVersion;
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
