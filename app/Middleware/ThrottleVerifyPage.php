<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\IpAddress;
use App\Core\Request;
use App\Core\Response;

/**
 * Page de vérification hébergée : limitation de débit par IP (préfixe /64 en IPv6), et en-têtes
 * « intégrable » posés d'emblée pour TOUTES ses réponses (y compris redirections et erreurs) : un
 * COOP same-origin sur une seule réponse couperait le lien entre le popup et la page du client.
 * Le contrôleur restreint ensuite frame-ancestors aux domaines autorisés du projet.
 */
final class ThrottleVerifyPage implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $request->setAttribute('security.frame_ancestors', []);
        [$max, $window] = $this->app->rateLimit('verify_page_ip');
        $limit = $this->app->rateLimiter()->attempt('verify_page_ip', IpAddress::rateLimitKey($request->ip()), $max, $window);
        if (!$limit->allowed) {
            throw new HttpException(429, ['Retry-After' => (string) $limit->retryAfter]);
        }

        return $next($request);
    }
}
