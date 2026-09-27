<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Réponse HTTP. Les en-têtes sont indexés sans tenir compte de la casse ; les cookies sont émis
 * comme autant d'en-têtes Set-Cookie distincts.
 */
final class Response
{
    /** @var array<string, array{0: string, 1: string}> nom en minuscules => [nom d'origine, valeur] */
    private array $headers = [];

    /** @var list<string> */
    private array $cookies = [];

    /** @param array<string, string> $headers */
    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Redirection interne uniquement : on refuse toute URL absolue ou protocole-relative pour
     * éliminer par construction les redirections ouvertes.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Redirection limitée aux chemins internes.');
        }

        return new self('', $status, ['Location' => $path]);
    }

    /**
     * Redirection vers une URL absolue EXTERNE déjà validée par l'appelant (return_url d'un client,
     * contrôlée par UrlGuard contre ses domaines autorisés). Refuse tout ce qui n'est pas http(s).
     */
    public static function redirectAway(string $url, int $status = 303): self
    {
        if (preg_match('#^https?://[^\s/?\#]+#i', $url) !== 1 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException('URL de redirection externe invalide.');
        }

        return new self('', $status, ['Location' => $url]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function setHeader(string $name, string $value): void
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Retour à la ligne interdit dans un en-tête.');
        }
        $this->headers[strtolower($name)] = [$name, $value];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }

        return $out;
    }

    public function addCookie(string $setCookieValue): void
    {
        $this->cookies[] = $setCookieValue;
    }

    /** @return list<string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}
