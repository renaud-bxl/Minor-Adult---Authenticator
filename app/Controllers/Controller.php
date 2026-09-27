<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\IpAddress;
use App\Core\RateLimitResult;
use App\Core\Request;
use App\Core\Response;
use App\Core\TextInput;
use App\Services\PasswordBlocklist;
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

    /** Compteur par adresse IP du client (préfixe /64 en IPv6). */
    protected function throttleIp(string $bucket, Request $request): RateLimitResult
    {
        return $this->throttle($bucket, IpAddress::rateLimitKey($request->ip()));
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
        $config = $this->app->config;

        return new PasswordPolicy(
            (int) $config->get('security.password.min_length'),
            (int) $config->get('security.password.max_length'),
            new PasswordBlocklist($this->app->path((string) $config->get('security.password.blocklist'))),
            // Nom du service et domaine : jamais acceptés dans un mot de passe.
            [(string) $config->get('app.name'), 'veriage', explode('.', (string) $config->get('app.domain'))[0]],
        );
    }

    /**
     * Adresse e-mail saisie, normalisée par TextInput, ou null si elle est invalide.
     */
    protected static function validEmail(string $raw): ?string
    {
        $email = TextInput::normalize($raw);

        return $email !== null
            && $email !== ''
            && strlen($email) <= self::EMAIL_MAX_LENGTH
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
