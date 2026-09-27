<?php

declare(strict_types=1);

namespace App\Verification;

use App\Core\HostMap;
use App\Core\IpAddress;

/**
 * Contrôle des URL fournies par les clients (return_url, webhooks) et des origines autorisées :
 * protection contre la SSRF et les redirections ouvertes.
 *
 * Règles (production) : https uniquement ; pas d'identifiants dans l'URL ; hôte nommé ou IP publique
 * (aucune adresse privée, locale, réservée, de documentation ou multicast, y compris sous forme
 * IPv4 encapsulée dans IPv6) ; origine couverte par un domaine autorisé du projet. Pour les envois
 * serveur (webhooks), le nom est résolu et TOUTES les adresses doivent être publiques ; la connexion
 * se fait ensuite sur l'adresse vérifiée (pas de seconde résolution : parade au DNS rebinding).
 *
 * $allowPrivate (développement uniquement, refusé en production) : autorise les adresses privées ou
 * locales, et http:// vers ces seules adresses (démo sur 127.0.0.1).
 */
final class UrlGuard
{
    /** Plages non routables publiquement (RFC 6890 et suivantes). */
    private const BLOCKED_RANGES = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        // IPv6
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /** @param (callable(string): list<string>)|null $resolver résolution DNS (A + AAAA), injectable en test */
    public function __construct(private readonly bool $allowPrivate = false, ?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? $resolver(...) : self::systemResolve(...);
    }

    /**
     * Valide une URL cible (return_url, webhook) sans résolution DNS.
     *
     * @param list<string> $allowedOrigins origines autorisées du projet
     * @return string|null code d'erreur stable, ou null si l'URL est acceptable
     */
    public function checkUrl(string $url, array $allowedOrigins): ?string
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return 'invalid_url';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'invalid_url';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials_not_allowed';
        }
        if (isset($parts['fragment'])) {
            return 'fragment_not_allowed';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->httpAllowedFor(strtolower($parts['host'])))) {
            return 'insecure_scheme';
        }
        $hostError = $this->checkHost(strtolower($parts['host']));
        if ($hostError !== null) {
            return $hostError;
        }
        $origin = HostMap::origin($url);
        if ($origin === null || !self::originAllowed($origin, $allowedOrigins)) {
            return 'origin_not_allowed';
        }

        return null;
    }

    /**
     * Valide une origine autorisée saisie par le client (« https://shop.example », « https://*.example.com »,
     * « http://127.0.0.1:8001 » en développement).
     *
     * @return string|null origine normalisée, ou null si elle est invalide
     */
    public function normalizeOrigin(string $origin): ?string
    {
        $origin = strtolower(trim($origin));
        if (preg_match('#^(https?)://(\*\.)?([a-z0-9.\-]+|\[[0-9a-f:.]+\])(?::(\d{1,5}))?$#D', $origin, $m) !== 1) {
            return null;
        }
        [, $scheme, $wildcard, $host] = $m;
        $port = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
        if (($scheme !== 'https' && !$this->httpAllowedFor($host)) || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }
        $bareHost = trim($host, '[]');
        $isIp = filter_var($bareHost, FILTER_VALIDATE_IP) !== false;
        if ($wildcard !== '' && ($isIp || substr_count($host, '.') < 1)) {
            return null;
        }
        if (!$isIp && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            return null;
        }
        if ($this->checkHost($host) !== null) {
            return null;
        }
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $wildcard . $host . ($port !== null && $port !== $default ? ':' . $port : '');
    }

    /**
     * Résout l'hôte et vérifie que toutes ses adresses sont publiques.
     *
     * @return array{0: ?string, 1: ?string} [adresse à utiliser, code d'erreur]
     */
    public function resolvePublic(string $host): array
    {
        $host = trim(strtolower($host), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->allowPrivate || self::isPublicIp($host) ? [$host, null] : [null, 'private_address'];
        }
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return [null, 'dns_failed'];
        }
        foreach ($ips as $ip) {
            if (!$this->allowPrivate && !self::isPublicIp($ip)) {
                return [null, 'private_address'];
            }
        }

        return [$ips[0], null];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (IpAddress::matchesAny($ip, self::BLOCKED_RANGES)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param list<string> $allowedOrigins */
    public static function originAllowed(string $origin, array $allowedOrigins): bool
    {
        $origin = strtolower($origin);
        foreach ($allowedOrigins as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $origin) {
                return true;
            }
            // « https://*.example.com » : tout sous-domaine (à un ou plusieurs niveaux), même schéma et port.
            if (preg_match('#^([a-z]+://)\*\.(.+)$#D', $allowed, $m) === 1
                && str_starts_with($origin, $m[1])
                && str_ends_with($origin, '.' . $m[2])
                && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)*$#D', substr($origin, strlen($m[1]), -strlen('.' . $m[2]))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * http:// n'est admis qu'en développement ($allowPrivate) et seulement vers une machine locale ou
     * privée (démo sur 127.0.0.1, domaines .localhost / .test) : jamais vers un hôte public.
     */
    private function httpAllowedFor(string $host): bool
    {
        if (!$this->allowPrivate) {
            return false;
        }
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return !self::isPublicIp($bare);
        }

        return $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }

    private function checkHost(string $host): ?string
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $this->allowPrivate || self::isPublicIp($bare) ? null : 'private_address';
        }
        if (!$this->allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost') || !str_contains($host, '.'))) {
            return 'private_address';
        }

        return null;
    }

    /** @return list<string> */
    private static function systemResolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
