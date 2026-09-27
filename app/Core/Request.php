<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Requête HTTP entrante. Les attributs portent l'état propre à la requête (session, paramètres de
 * route, langue), ce qui évite tout état global et permet de rejouer des requêtes en test.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers noms en minuscules
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath(is_string($path) ? rawurldecode($path) : '/'),
            $_GET,
            $_POST,
            $headers,
            array_filter($_COOKIE, 'is_string'),
            $_SERVER,
        );
    }

    /** Chemin canonique : un seul « / » entre segments, sans barre finale (sauf la racine). */
    public static function normalizePath(string $path): string
    {
        return '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethodSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** Paramètre de query string, uniquement s'il s'agit d'une chaîne (pas de tableau injecté). */
    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** Champ de formulaire, uniquement s'il s'agit d'une chaîne. */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adresse IP du client. En production (HestiaCP), nginx transmet l'IP réelle et Apache la
     * restaure via mod_remoteip : REMOTE_ADDR est donc fiable. On n'interprète jamais X-Forwarded-For.
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /** Paramètre extrait du motif de la route (ex. {lang}). */
    public function routeParam(string $name): ?string
    {
        $params = $this->attributes['route_params'] ?? [];

        return isset($params[$name]) ? (string) $params[$name] : null;
    }

    public function session(): Session
    {
        $session = $this->attributes['session'] ?? null;
        if (!$session instanceof Session) {
            throw new \LogicException('Aucune session : le middleware StartSession n\'est pas actif sur cette route.');
        }

        return $session;
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }
}
