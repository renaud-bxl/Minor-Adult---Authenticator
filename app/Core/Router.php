<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur : méthodes HTTP, paramètres « {nom} » ou « {nom:regex} », groupes avec préfixe et
 * middlewares. Les chemins sont comparés sous leur forme canonique (sans barre finale).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private string $groupPrefix = '';

    /** @var list<class-string> */
    private array $groupMiddleware = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $pattern, array $handler): Route
    {
        return $this->add(['GET'], $pattern, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $pattern, array $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /**
     * @param list<string>                       $methods
     * @param array{0: class-string, 1: string}  $handler
     */
    public function add(array $methods, string $pattern, array $handler): Route
    {
        $route = new Route(
            array_map('strtoupper', $methods),
            Request::normalizePath($this->groupPrefix . '/' . ltrim($pattern, '/')),
            $handler,
            $this->groupMiddleware,
        );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Déclare des routes partageant un préfixe et des middlewares (les groupes s'imbriquent).
     *
     * @param list<class-string>     $middleware
     * @param callable(self): void   $define
     */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        [$previousPrefix, $previousMiddleware] = [$this->groupPrefix, $this->groupMiddleware];
        $this->groupPrefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];
        try {
            $define($this);
        } finally {
            [$this->groupPrefix, $this->groupMiddleware] = [$previousPrefix, $previousMiddleware];
        }
    }

    /**
     * @return array{0: Route, 1: array<string, string>}
     *
     * @throws HttpException 404 si aucun motif ne correspond, 405 (avec Allow) si seule la méthode diffère
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = Request::normalizePath($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);
            if ($params === null) {
                continue;
            }
            // HEAD est servi par la route GET correspondante (le corps est ignoré à l'envoi).
            if (in_array($method, $route->methods, true) || ($method === 'HEAD' && in_array('GET', $route->methods, true))) {
                return [$route, $params];
            }
            array_push($allowed, ...$route->methods);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            throw new HttpException(405, ['Allow' => implode(', ', $allowed)]);
        }

        throw new HttpException(404);
    }
}
