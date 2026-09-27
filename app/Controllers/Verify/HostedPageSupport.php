<?php

declare(strict_types=1);

namespace App\Controllers\Verify;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\VerificationSession;
use App\Verification\PageToken;
use App\Verification\UrlGuard;
use App\Verification\VerificationService;

/**
 * Outils communs aux pages de la session hébergée (/s/{session}) : chargement de la session, jeton
 * signé des formulaires (sans cookie), langue, paramètres d'intégration conservés d'une étape à l'autre.
 *
 * @property \App\Core\Application $app
 */
trait HostedPageSupport
{
    /** @return array{0: Project, 1: VerificationSession, 2: VerificationService} */
    protected function load(Request $request): array
    {
        $service = VerificationService::fromApplication($this->app);
        $loaded = $service->load((string) $request->routeParam('session'));
        if ($loaded === null) {
            throw new HttpException(404);
        }
        $this->app->translator()->setLocale($this->locale($request, $loaded[1]));
        // Intégrable (iframe, modale) uniquement par les domaines autorisés du projet, pour toutes
        // les réponses de la session (pages, redirections, erreurs).
        $request->setAttribute('security.frame_ancestors', $loaded[0]->allowedOrigins);

        return [...$loaded, $service];
    }

    /** @return array{0: Project, 1: VerificationSession, 2: VerificationService} */
    protected function loadForPost(Request $request): array
    {
        $loaded = $this->load($request);
        if (!$this->pageToken()->validate($request->input(PageToken::FIELD), $loaded[1]->publicId, time())) {
            throw new HttpException(419);
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function page(string $template, array $data): Response
    {
        $data['noindex'] = true;

        return Response::html($this->app->view()->render($template, $data, 'layouts/verify'));
    }

    /** @param array<string, string> $extra */
    protected function back(Request $request, VerificationSession $session, ?string $notice, array $extra = []): Response
    {
        $embed = ['mode' => $request->query('embed'), 'origin' => $request->query('origin')];
        $params = array_filter([
            'lang' => $request->query('lang'),
            'embed' => in_array($embed['mode'], HostedPageController::EMBED_MODES, true) ? $embed['mode'] : '',
            'origin' => $embed['origin'],
            'notice' => $notice ?? '',
            ...$extra,
        ], static fn (string $v): bool => $v !== '');

        return Response::redirect('/s/' . $session->publicId . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)), 303);
    }

    /**
     * Mode d'intégration et origine de la page parente. L'origine n'est retenue que si elle fait
     * partie des domaines autorisés du projet : c'est la seule cible des postMessage de la page.
     *
     * @return array{mode: ?string, origin: ?string}
     */
    protected function embed(Request $request, Project $project): array
    {
        $mode = $request->query('embed');
        $origin = $request->query('origin');
        $mode = in_array($mode, HostedPageController::EMBED_MODES, true) ? $mode : null;
        $origin = $mode !== null && $origin !== '' && strlen($origin) <= 255 && UrlGuard::originAllowed($origin, $project->allowedOrigins)
            && preg_match('#^https?://[a-z0-9.\-:\[\]]+$#D', $origin) === 1 ? $origin : null;

        return ['mode' => $mode, 'origin' => $origin];
    }

    /** Paramètres d'URL à conserver d'une étape à l'autre. @param array{mode: ?string, origin: ?string} $embed */
    protected function stateQuery(array $embed, string $locale): string
    {
        $params = array_filter(['lang' => $locale, 'embed' => $embed['mode'], 'origin' => $embed['origin']], static fn (?string $v): bool => $v !== null);

        return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array{mode: ?string, origin: ?string} $embed
     * @return array<string, string> langue => URL
     */
    protected function languageLinks(VerificationSession $session, array $embed): array
    {
        $links = [];
        foreach ($this->app->translator()->enabled() as $code) {
            $links[$code] = '/s/' . $session->publicId . $this->stateQuery($embed, $code);
        }

        return $links;
    }

    /** Langue : ?lang= > langue demandée à la création de la session > Accept-Language > anglais. */
    protected function locale(Request $request, VerificationSession $session): string
    {
        $translator = $this->app->translator();
        $query = $request->query('lang');
        if ($translator->isEnabled($query)) {
            return $query;
        }
        if ($session->lang !== null && $translator->isEnabled($session->lang)) {
            return $session->lang;
        }

        return $this->app->negotiator()->negotiate(acceptLanguage: $request->header('Accept-Language'));
    }

    protected function pageToken(): PageToken
    {
        return new PageToken($this->app->crypto()->deriveKey('hosted-page'));
    }
}
