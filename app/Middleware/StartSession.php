<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;

/**
 * Ouvre la session à partir du cookie, la rend disponible (requête + vues), puis la persiste et
 * émet le cookie si nécessaire.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $session = $this->app->newSession();
        $incomingId = $request->cookie($session->cookieName());
        $session->start($incomingId);
        $request->setAttribute('session', $session);

        $view = $this->app->view();
        $view->share('csrf', new Csrf($session));
        $view->share('isAuthenticated', is_int($session->get('user_id')));
        $view->share('flashSuccess', $session->getFlash('success'));
        $view->share('flashError', $session->getFlash('error'));
        $view->share('flashInfo', $session->getFlash('info'));

        $response = $next($request);

        if ($session->save() && $session->id() !== $incomingId) {
            $response->addCookie($session->cookieHeader());
        }

        return $response;
    }
}
