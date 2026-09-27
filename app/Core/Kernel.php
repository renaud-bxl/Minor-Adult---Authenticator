<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareInterface;
use App\Middleware\SecurityHeaders;

/**
 * Cycle de vie d'une requête : routage, chaîne de middlewares de la route, contrôleur. Toute
 * exception est convertie en réponse d'erreur (page traduite ou JSON), et les en-têtes de sécurité
 * s'appliquent à toutes les réponses, y compris les erreurs.
 */
final class Kernel
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request): Response
    {
        return (new SecurityHeaders($this->app))->process($request, fn (Request $r): Response => $this->dispatch($r));
    }

    private function dispatch(Request $request): Response
    {
        try {
            [$route, $params] = $this->app->router()->match($request->method(), $request->path());
            $request->setAttribute('route_params', $params);

            $next = function (Request $r) use ($route): Response {
                [$class, $method] = $route->handler;

                return (new $class($this->app))->{$method}($r);
            };
            foreach (array_reverse($route->middlewares()) as $class) {
                $middleware = new $class($this->app);
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new \LogicException($class . ' n\'est pas un middleware.');
                }
                $next = static fn (Request $r): Response => $middleware->process($r, $next);
            }

            return $next($request);
        } catch (\Throwable $e) {
            return (new ErrorRenderer($this->app))->render($request, $e);
        }
    }
}
