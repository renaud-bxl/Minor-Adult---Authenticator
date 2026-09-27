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
            $areas = $this->app->hostMap()->areasFor($request->host());
            if ($areas === [] && ($apex = $this->apexRedirect($request)) !== null) {
                return $apex;
            }
            [$route, $params] = $this->app->router()->match($request->method(), $request->path(), $areas);
            $request->setAttribute('route_params', $params);
            $request->setAttribute('area', $route->host);

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

    /** Domaine nu (ex. veriage.eu) : redirection permanente vers le site (APP_URL, ex. www.veriage.eu). */
    private function apexRedirect(Request $request): ?Response
    {
        $domain = strtolower((string) $this->app->config->get('app.domain'));
        $siteUrl = (string) $this->app->config->get('app.url');
        $host = (string) preg_replace('/:\d+$/', '', $request->host());
        if ($domain === '' || $host !== $domain || HostMap::authority($siteUrl) === $domain) {
            return null;
        }

        return new Response('', 301, ['Location' => rtrim($siteUrl, '/') . $request->path()]);
    }
}
