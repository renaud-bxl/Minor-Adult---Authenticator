<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Transforme une exception en réponse : page HTML traduite pour le site, JSON normalisé pour l'API.
 * Les erreurs inattendues sont journalisées (sans donnée personnelle) et rendues en 500 générique.
 */
final class ErrorRenderer
{
    /** Clés de traduction littérales (détectables par tools/check_translations.php). */
    private const PAGES = [
        400 => ['errors.bad_request.title', 'errors.bad_request.message', 'bad_request'],
        404 => ['errors.not_found.title', 'errors.not_found.message', 'not_found'],
        405 => ['errors.method_not_allowed.title', 'errors.method_not_allowed.message', 'method_not_allowed'],
        419 => ['errors.csrf.title', 'errors.csrf.message', 'csrf_token_mismatch'],
        429 => ['errors.too_many_requests.title', 'errors.too_many_requests.message', 'too_many_requests'],
        500 => ['errors.server.title', 'errors.server.message', 'server_error'],
    ];

    /** Messages des codes d'erreur de l'API (codes stables, messages traduits). */
    private const API_MESSAGES = [
        'bad_request' => 'errors.api.bad_request',
        'invalid_json' => 'errors.api.invalid_json',
        'unauthorized' => 'errors.api.unauthorized',
        'insufficient_credits' => 'errors.api.insufficient_credits',
        'not_found' => 'errors.api.not_found',
        'method_not_allowed' => 'errors.api.method_not_allowed',
        'payload_too_large' => 'errors.api.payload_too_large',
        'unsupported_media_type' => 'errors.api.unsupported_media_type',
        'validation_failed' => 'errors.api.validation_failed',
        'rate_limited' => 'errors.api.rate_limited',
        'email_locked' => 'errors.api.email_locked',
        'idempotency_key_reused' => 'errors.api.idempotency_key_reused',
        'idempotency_in_progress' => 'errors.api.idempotency_in_progress',
        'server_error' => 'errors.api.server_error',
    ];

    /** Statut HTTP → code d'API, pour les erreurs génériques (routeur, 500). */
    private const API_CODES = [
        400 => 'bad_request', 404 => 'not_found', 405 => 'method_not_allowed', 419 => 'bad_request',
        429 => 'rate_limited', 500 => 'server_error',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function render(Request $request, \Throwable $e): Response
    {
        if ($request->isApi()) {
            return $this->renderApi($request, $e);
        }
        $status = $e instanceof HttpException ? $e->status() : 500;
        if (!isset(self::PAGES[$status])) {
            $status = 500;
        }
        if ($status === 500) {
            $this->app->logger()->error('request_failed', [...Logger::exceptionContext($e), 'method' => $request->method()]);
        }

        try {
            $response = $this->build($request, $status, $e);
        } catch (\Throwable $renderFailure) {
            // Le rendu lui-même a échoué (vue, traductions…) : réponse minimale, sans dépendance.
            $this->app->logger()->error('error_page_failed', Logger::exceptionContext($renderFailure));
            $response = new Response((string) $status, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        foreach ($e instanceof HttpException ? $e->headers() : [] as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * JSON normalisé : { "error": { "code", "message", "details"? } }. Les codes sont stables et
     * documentés ; le message est traduit selon Accept-Language (anglais par défaut).
     */
    private function renderApi(Request $request, \Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            [$status, $code, $details, $headers] = [$e->status(), $e->errorCode(), $e->details(), $e->headers()];
        } else {
            $status = $e instanceof HttpException && isset(self::API_CODES[$e->status()]) ? $e->status() : 500;
            [$code, $details, $headers] = [self::API_CODES[$status], [], $e instanceof HttpException ? $e->headers() : []];
        }
        if ($status >= 500) {
            $this->app->logger()->error('request_failed', [...Logger::exceptionContext($e), 'method' => $request->method()]);
        }
        try {
            $translator = $this->app->translator();
            $locale = $this->app->negotiator()->negotiate(acceptLanguage: $request->header('Accept-Language'));
            $message = $translator->get(self::API_MESSAGES[$code] ?? 'errors.api.server_error', [], $locale);
        } catch (\Throwable) {
            $message = $code;
        }
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $response = Response::json(['error' => $error], $status);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    private function build(Request $request, int $status, \Throwable $e): Response
    {
        [$titleKey, $messageKey] = self::PAGES[$status];
        $translator = $this->app->translator();
        $translator->setLocale($this->locale($request));

        $area = $this->area($request);
        $html = $this->app->view()->render('errors/error', [
            'status' => $status,
            'title' => $translator->get($titleKey),
            'message' => $translator->get($messageKey),
            'debug' => $status === 500 && $this->app->config->get('app.debug') === true ? $e::class . ': ' . $e->getMessage() : null,
            'pageTitle' => $translator->get($titleKey),
            'noindex' => true,
            // Hors du site (module, démo), le lien d'accueil pointe vers le site public.
            'homeUrl' => $area === HostMap::SITE ? url('/') : absolute_url(url('/')),
        ], $area === HostMap::SITE ? 'layouts/app' : 'layouts/verify');

        return Response::html($html, $status);
    }

    /** Zone de la requête : celle de la route, sinon celle de l'hôte (le site en priorité). */
    private function area(Request $request): string
    {
        $area = $request->attribute('area');
        if (is_string($area)) {
            return $area;
        }
        $areas = $this->app->hostMap()->areasFor($request->host());

        return $areas === [] || in_array(HostMap::SITE, $areas, true) ? HostMap::SITE : $areas[0];
    }

    /** Langue de la page d'erreur : celle déjà fixée par SetLocale, sinon préfixe d'URL, sinon Accept-Language. */
    private function locale(Request $request): string
    {
        $locale = $request->attribute('locale');
        if (is_string($locale)) {
            return $locale;
        }
        $prefix = explode('/', $request->path())[1] ?? '';

        return $this->app->negotiator()->negotiate(urlLang: $prefix, acceptLanguage: $request->header('Accept-Language'));
    }
}
