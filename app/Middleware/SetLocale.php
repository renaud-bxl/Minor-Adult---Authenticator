<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\AccountRepository;

/**
 * Langue des pages préfixées /{lang}/… (priorités : voir LocaleNegotiator).
 *
 * - « ?lang=xx » (GET) : choix explicite, mémorisé en session (et comme préférence du compte si
 *   l'utilisateur est connecté), puis redirection vers l'URL propre /xx/… ;
 * - langue mémorisée en session (choix explicite ou préférence du compte chargée à la connexion) :
 *   une requête GET vers une autre langue est redirigée vers la langue mémorisée ;
 * - sinon, le préfixe d'URL fait foi.
 */
final class SetLocale implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $translator = $this->app->translator();
        $urlLang = (string) $request->routeParam('lang');
        if (!$translator->isEnabled($urlLang)) {
            throw new HttpException(404);
        }

        $session = $request->session();
        $rest = (string) substr($request->path(), strlen('/' . $urlLang));
        $rest = $rest === '' ? '/' : $rest;
        $query = $request->queryAll();

        // Uniquement en GET/HEAD : une requête POST ne doit pas modifier la préférence avant le contrôle CSRF.
        $queryLang = $request->isMethodSafe() ? $request->query('lang') : '';
        if ($queryLang !== '' && $translator->isEnabled($queryLang)) {
            $session->set('locale', $queryLang);
            $userId = $session->get('user_id');
            if (is_int($userId)) {
                (new AccountRepository($this->app->db()))->updateLocaleForUser($userId, $queryLang);
            }
            unset($query['lang']);

            return $this->redirect($queryLang, $rest, $query);
        }

        $sessionLang = $session->get('locale');
        $locale = $this->app->negotiator()->negotiate(
            accountLang: is_string($sessionLang) ? $sessionLang : null,
            urlLang: $urlLang,
        );
        if ($locale !== $urlLang && $request->isMethodSafe()) {
            return $this->redirect($locale, $rest, $query);
        }

        $translator->setLocale($locale);
        $request->setAttribute('locale', $locale);
        $this->shareLayoutData($rest);

        return $next($request);
    }

    /** Données du layout : chemin sans préfixe (sélecteur de langue) et liens hreflang. */
    private function shareLayoutData(string $rest): void
    {
        $alternates = [];
        foreach ($this->app->translator()->enabled() as $code) {
            $alternates[$code] = absolute_url(url($rest, $code));
        }
        $fallback = $this->app->translator()->fallback();
        $alternates['x-default'] = $rest === '/' ? absolute_url('/') : $alternates[$fallback];

        $view = $this->app->view();
        $view->share('currentPath', $rest);
        $view->share('alternates', $alternates);
    }

    /** @param array<string, mixed> $query */
    private function redirect(string $lang, string $rest, array $query): Response
    {
        $target = url($rest, $lang);
        if ($query !== []) {
            $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return Response::redirect($target);
    }
}
