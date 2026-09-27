<?php

declare(strict_types=1);

namespace Tests\Support;

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

    public function __construct(private readonly string $ip = '203.0.113.10')
    {
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array $body = [], array $headers = []): Response
    {
        $app = TestApplication::boot();
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = new Request(
            $method,
            Request::normalizePath(rawurldecode($path)),
            $query,
            $body,
            array_change_key_case($headers, CASE_LOWER),
            $this->cookies,
            ['REMOTE_ADDR' => $this->ip],
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

    /** @param array<string, string> $body */
    public function post(string $uri, array $body = []): Response
    {
        return $this->request('POST', $uri, $body);
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
