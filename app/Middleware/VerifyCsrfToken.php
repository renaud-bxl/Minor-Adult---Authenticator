<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Refuse (419) toute requête non sûre sans jeton CSRF valide (champ _token ou en-tête X-CSRF-Token). */
final class VerifyCsrfToken implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$request->isMethodSafe()) {
            $candidate = $request->input(Csrf::FIELD);
            if ($candidate === '') {
                $candidate = (string) $request->header(Csrf::HEADER);
            }
            if (!(new Csrf($request->session()))->validate($candidate)) {
                $this->app->logger()->info('csrf_rejected', ['path' => $request->path()]);
                throw new HttpException(419);
            }
        }

        return $next($request);
    }
}
