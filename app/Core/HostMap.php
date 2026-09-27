<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Zones de l'application par hôte (routage par sous-domaine) :
 * - « site »   : vitrine et espace client (APP_URL, ex. www.veriage.eu) ;
 * - « verify » : API, page de vérification hébergée et widget (VERIFY_URL, ex. verify.veriage.eu) ;
 * - « demo »   : plateforme cliente fictive (DEMO_URL), seulement si la démo est activée.
 *
 * Plusieurs zones peuvent partager un hôte (développement : tout sur 127.0.0.1:8000). Un hôte
 * inconnu ne sert aucune zone : toutes les routes y répondent 404 (pas de repli sur l'en-tête Host,
 * qui est fourni par le client).
 */
final class HostMap
{
    public const SITE = 'site';
    public const VERIFY = 'verify';
    public const DEMO = 'demo';

    /** @var array<string, string> zone => autorité (hôte[:port]) */
    private array $authorities = [];

    /** @param array<string, string> $urls zone => URL de base */
    public function __construct(array $urls)
    {
        foreach ($urls as $area => $url) {
            $authority = self::authority($url);
            if ($authority !== null) {
                $this->authorities[$area] = $authority;
            }
        }
    }

    public static function fromConfig(Config $config): self
    {
        $urls = [
            self::SITE => (string) $config->get('app.url'),
            self::VERIFY => (string) ($config->get('app.verify_url') ?: $config->get('app.url')),
        ];
        if ($config->get('app.demo_enabled') === true && (string) $config->get('app.demo_url') !== '') {
            $urls[self::DEMO] = (string) $config->get('app.demo_url');
        }

        return new self($urls);
    }

    /** @return list<string> zones servies par cette autorité (« hôte » ou « hôte:port ») */
    public function areasFor(string $authority): array
    {
        // Un port par défaut explicite (« :443 », « :80 ») désigne le même hôte.
        $bare = (string) preg_replace('/:(?:80|443)$/', '', $authority);

        return array_keys(array_filter(
            $this->authorities,
            static fn (string $a): bool => $authority !== '' && ($a === $authority || $a === $bare),
        ));
    }

    public function has(string $area): bool
    {
        return isset($this->authorities[$area]);
    }

    /** Autorité normalisée d'une URL : hôte en minuscules, port seulement s'il n'est pas celui du schéma. */
    public static function authority(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = $parts['port'] ?? null;
        $default = ['http' => 80, 'https' => 443][strtolower($parts['scheme'])] ?? null;

        return $port === null || $port === $default ? $host : $host . ':' . $port;
    }

    /** Origine (schéma://hôte[:port]) d'une URL, ou null. */
    public static function origin(string $url): ?string
    {
        $authority = self::authority($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $authority === null ? null : $scheme . '://' . $authority;
    }
}
