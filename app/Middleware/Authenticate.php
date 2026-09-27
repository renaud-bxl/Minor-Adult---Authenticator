<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Models\UserRepository;

/**
 * Réservé aux utilisateurs connectés. La session est invalidée si l'utilisateur n'existe plus ou si
 * son mot de passe a changé depuis la connexion (auth_version), ce qui déconnecte toutes les autres
 * sessions après une réinitialisation.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');
        $user = is_int($userId) ? (new UserRepository($this->app->db()))->findById($userId) : null;

        if ($user === null || (int) $user['auth_version'] !== $session->get('auth_version')) {
            if ($user !== null || $userId !== null) {
                $session->invalidate();
            }
            $session->flash('info', 'site.auth.login_required');

            return Response::redirect(url('/login'));
        }

        $request->setAttribute('user', $user);

        return $next($request);
    }
}
