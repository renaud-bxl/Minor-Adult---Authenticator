<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Response;

abstract class Controller
{
    public function __construct(protected readonly Application $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->app->view()->render($template, $data), $status);
    }

    /** Redirection vers un chemin du site dans la langue courante (ex. « /login » → « /fr/login »). */
    protected function redirectTo(string $path): Response
    {
        return Response::redirect(url($path));
    }
}
