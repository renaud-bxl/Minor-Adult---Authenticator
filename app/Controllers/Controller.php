<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\RateLimitResult;
use App\Core\Response;
use App\Services\PasswordPolicy;

abstract class Controller
{
    private const EMAIL_MAX_LENGTH = 254;

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

    /** Enregistre une tentative dans le compteur $bucket (limites dans config/security.php). */
    protected function throttle(string $bucket, string $identifier): RateLimitResult
    {
        [$max, $window] = $this->app->rateLimit($bucket);

        return $this->app->rateLimiter()->attempt($bucket, $identifier, $max, $window);
    }

    protected function throttledMessage(RateLimitResult $limit): string
    {
        return __('site.form.throttled', ['minutes' => max(1, (int) ceil($limit->retryAfter / 60))]);
    }

    /** Complète une réponse 429 avec l'en-tête Retry-After. */
    protected function throttled(Response $response, RateLimitResult $limit): Response
    {
        $response->setHeader('Retry-After', (string) $limit->retryAfter);

        return $response;
    }

    protected function passwordPolicy(): PasswordPolicy
    {
        return new PasswordPolicy(
            (int) $this->app->config->get('security.password.min_length'),
            (int) $this->app->config->get('security.password.max_length'),
        );
    }

    protected static function isValidEmail(string $email): bool
    {
        return $email !== ''
            && strlen($email) <= self::EMAIL_MAX_LENGTH
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
