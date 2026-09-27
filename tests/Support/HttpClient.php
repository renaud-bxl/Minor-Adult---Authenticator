<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\HostMap;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

/**
 * Client HTTP en mémoire : chaque requête passe par le Kernel réel avec une application neuve
 * (comme un processus PHP par requête) et un bocal à cookies.
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @param string      $ip   adresse du client (REMOTE_ADDR)
     * @param string|null $host en-tête Host (par défaut : l'hôte du site, APP_URL)
     */
    /** @param array<string, mixed> $overrides surcharges de configuration de chaque requête */
    public function __construct(
        private readonly string $ip = '203.0.113.10',
        private readonly ?string $host = null,
        private readonly array $overrides = [],
    ) {
    }

    /** Client adressé à l'hôte du module de vérification (API, page hébergée). */
    public static function verify(string $ip = '203.0.113.10'): self
    {
        return new self($ip, (string) HostMap::authority((string) TestApplication::boot()->config->get('app.verify_url')));
    }

    /**
     * Client adressé à l'hôte de la démonstration.
     *
     * @param array<string, mixed> $overrides
     */
    public static function demo(array $overrides = []): self
    {
        return new self('203.0.113.10', (string) HostMap::authority((string) TestApplication::boot()->config->get('app.demo_url')), $overrides);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array $body = [], array $headers = [], string $rawBody = ''): Response
    {
        $app = TestApplication::boot($this->overrides);
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] ??= $this->host ?? (string) HostMap::authority((string) $app->config->get('app.url'));
        $request = new Request(
            $method,
            Request::normalizePath(rawurldecode($path)),
            $query,
            $body,
            $headers,
            $this->cookies,
            ['REMOTE_ADDR' => $this->ip],
            [],
            $rawBody,
        );
        $response = (new Kernel($app))->handle($request);
        // Comme public/index.php : traitements reportés (e-mails) exécutés après la réponse.
        $app->runDeferred();

        foreach ($response->cookies() as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[$name] = $value;
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $body = [], array $headers = []): Response
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    /**
     * Requête JSON (API) : corps encodé, Content-Type application/json.
     *
     * @param array<string, string> $headers
     */
    public function json(string $method, string $uri, mixed $data = null, array $headers = []): Response
    {
        $raw = $data === null ? '' : (is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($data !== null) {
            $headers['Content-Type'] ??= 'application/json';
        }

        return $this->request($method, $uri, [], $headers, $raw);
    }

    /** Jeton CSRF extrait d'un formulaire de la page. */
    public function csrfToken(string $uri): string
    {
        $html = $this->get($uri)->body();
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m) !== 1) {
            throw new \RuntimeException('Jeton CSRF introuvable sur ' . $uri);
        }

        return $m[1];
    }

    /** @param array<string, string> $body */
    public function submit(string $formUri, string $action, array $body): Response
    {
        return $this->post($action, ['_token' => $this->csrfToken($formUri), ...$body]);
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function forgetCookies(): void
    {
        $this->cookies = [];
    }
}
