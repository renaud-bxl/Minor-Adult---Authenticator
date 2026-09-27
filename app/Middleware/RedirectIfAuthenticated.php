<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;

/** Pages réservées aux visiteurs (connexion, inscription…) : un utilisateur connecté va au tableau de bord. */
final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (is_int($request->session()->get('user_id'))) {
            return Response::redirect(url('/dashboard'));
        }

        return $next($request);
    }
}
