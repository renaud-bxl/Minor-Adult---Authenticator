<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Requête HTTP entrante. Les attributs portent l'état propre à la requête (session, paramètres de
 * route, langue), ce qui évite tout état global et permet de rejouer des requêtes en test.
 */
final class Request
{
    /** Taille maximale d'un corps JSON accepté (API). */
    public const MAX_JSON_BYTES = 65536;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null corps JSON décodé (mémoïsé) */
    private ?array $json = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers noms en minuscules
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     * @param list<string>          $trustedProxies proxys dont l'en-tête X-Forwarded-For est cru
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
        private readonly array $trustedProxies = [],
        private readonly string $rawBody = '',
    ) {
    }

    /** @param list<string> $trustedProxies */
    public static function fromGlobals(array $trustedProxies = []): self
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
        // Apache + PHP-FPM ne transmet pas toujours Authorization tel quel : la règle de réécriture
        // de public/.htaccess la place dans REDIRECT_HTTP_AUTHORIZATION (API Bearer, phase 2).
        if (!isset($headers['authorization']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '') {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        // Corps brut lu uniquement pour le JSON (API), borné : un octet de plus que la limite suffit
        // à détecter un dépassement sans charger un corps arbitrairement grand en mémoire.
        $rawBody = '';
        if (self::isJsonContentType($headers['content-type'] ?? '')) {
            $rawBody = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath(is_string($path) ? rawurldecode($path) : '/'),
            $_GET,
            $_POST,
            $headers,
            array_filter($_COOKIE, 'is_string'),
            $_SERVER,
            $trustedProxies,
            $rawBody,
        );
    }

    public static function isJsonContentType(string $contentType): bool
    {
        return preg_match('#^application/(?:[a-z0-9.+-]+\+)?json\s*(?:;|$)#i', trim($contentType)) === 1;
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

    /**
     * Corps JSON de la requête (objet JSON uniquement).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 415 (type de contenu), 413 (taille), 400 (JSON invalide ou non-objet)
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (!self::isJsonContentType((string) $this->header('Content-Type'))) {
            throw new ApiException(415, 'unsupported_media_type');
        }
        if (strlen($this->rawBody) > self::MAX_JSON_BYTES) {
            throw new ApiException(413, 'payload_too_large');
        }
        try {
            $data = json_decode($this->rawBody, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException(400, 'invalid_json');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiException(400, 'invalid_json');
        }

        return $this->json = $data;
    }

    /** Corps brut (lu seulement pour les requêtes JSON, borné à MAX_JSON_BYTES + 1 octet). */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Autorité demandée (en-tête Host : « hôte » ou « hôte:port »), en minuscules, sans point final.
     * Chaîne vide si l'en-tête est absent ou mal formé (aucune route liée à un hôte ne correspondra).
     */
    public function host(): string
    {
        $host = strtolower(trim((string) $this->header('Host')));
        $host = (string) preg_replace('/\.(?=:\d+$|$)/', '', $host);

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::\d{1,5})?$/D', $host) === 1
            ? $host : '';
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
     * Adresse IP du client. Par défaut REMOTE_ADDR (HestiaCP : Apache la restaure via mod_remoteip).
     * X-Forwarded-For n'est interprété que si REMOTE_ADDR est un proxy déclaré dans TRUSTED_PROXIES.
     */
    public function ip(): string
    {
        return IpAddress::client(
            (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'),
            $this->header('X-Forwarded-For'),
            $this->trustedProxies,
        );
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
