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

    public function __construct(private readonly Application $app)
    {
    }

    public function render(Request $request, \Throwable $e): Response
    {
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

    private function build(Request $request, int $status, \Throwable $e): Response
    {
        [$titleKey, $messageKey, $code] = self::PAGES[$status];
        $translator = $this->app->translator();
        $translator->setLocale($this->locale($request));

        if ($request->isApi()) {
            return Response::json(['error' => ['code' => $code, 'message' => $translator->get($messageKey)]], $status);
        }

        $html = $this->app->view()->render('errors/error', [
            'status' => $status,
            'title' => $translator->get($titleKey),
            'message' => $translator->get($messageKey),
            'debug' => $status === 500 && $this->app->config->get('app.debug') === true ? $e::class . ': ' . $e->getMessage() : null,
            'pageTitle' => $translator->get($titleKey),
            'noindex' => true,
        ]);

        return Response::html($html, $status);
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
