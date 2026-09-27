<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Adresses IP : détermination de l'adresse du client derrière un proxy de confiance, troncature
 * avant stockage (RGPD) et regroupement pour la limitation de débit.
 */
final class IpAddress
{
    /**
     * Troncature avant stockage (journal d'audit) : /24 en IPv4, /48 en IPv6.
     * L'adresse ainsi obtenue ne désigne plus un abonné mais un bloc d'adresses.
     */
    public static function truncate(string $ip): ?string
    {
        return self::mask($ip, 24, 48);
    }

    /**
     * Identifiant de limitation de débit : l'adresse complète en IPv4, le préfixe /64 en IPv6
     * (un abonné IPv6 dispose au minimum d'un /64 : limiter adresse par adresse serait contournable).
     */
    public static function rateLimitKey(string $ip): string
    {
        return self::mask($ip, 32, 64) ?? $ip;
    }

    /**
     * Adresse du client. X-Forwarded-For n'est lu que si la connexion vient d'un proxy de confiance
     * (ex. nginx de HestiaCP quand Apache ne restaure pas l'IP via mod_remoteip) ; la liste est alors
     * parcourue de droite à gauche en ignorant les proxys de confiance : les entrées de gauche, fournies
     * par le client, ne sont jamais crues.
     *
     * @param list<string> $trustedProxies adresses ou plages CIDR (ex. « 127.0.0.1 », « 10.0.0.0/8 »)
     */
    public static function client(string $remoteAddr, ?string $forwardedFor, array $trustedProxies): string
    {
        if ($forwardedFor === null || $trustedProxies === [] || !self::matchesAny($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }
        foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::matchesAny($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $remoteAddr;
    }

    /** @param list<string> $ranges */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$network, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $networkPacked = @inet_pton(trim($network));
            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? 8 * strlen($packed) : (int) $bits;
            if ($bits < 0 || $bits > 8 * strlen($packed)) {
                continue;
            }
            if (self::prefix($packed, $bits) === self::prefix($networkPacked, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function mask(string $ip, int $ipv4Bits, int $ipv6Bits): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $bits = strlen($packed) === 4 ? $ipv4Bits : $ipv6Bits;

        return (string) inet_ntop(str_pad(self::prefix($packed, $bits), strlen($packed), "\0"));
    }

    /** Les $bits premiers bits de l'adresse binaire (octet final partiel masqué). */
    private static function prefix(string $packed, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $prefix = substr($packed, 0, $bytes);
        $rest = $bits % 8;
        if ($rest !== 0) {
            $prefix .= chr(ord($packed[$bytes]) & (0xFF << (8 - $rest)) & 0xFF);
        }

        return $prefix;
    }
}
