<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Troncature des adresses IP avant stockage (journal d'audit) : /24 en IPv4, /48 en IPv6.
 * L'adresse ainsi obtenue ne désigne plus un abonné mais un bloc d'adresses.
 */
final class IpAddress
{
    public static function truncate(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 4) {
            $packed = substr($packed, 0, 3) . "\0";
        } else {
            $packed = substr($packed, 0, 6) . str_repeat("\0", 10);
        }

        return (string) inet_ntop($packed);
    }
}
