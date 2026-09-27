<?php

declare(strict_types=1);

namespace App\Controllers\Verify;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\VerificationSession;
use App\Verification\PageToken;
use App\Verification\UrlGuard;
use App\Verification\VerificationService;

/**
 * Page de vérification hébergée « /s/{session} » (hôte verify.), affichée en pleine page, en popup,
 * en modale ou en iframe (widget). Aucun cookie : l'état est en base, désigné par l'identifiant de
 * session de l'URL ; les formulaires portent un jeton signé (PageToken) ; les paramètres d'affichage
 * (langue, mode d'intégration, origine de la page parente) voyagent dans l'URL.
 *
 * Parcours : consentement (art. 9 RGPD) → code reçu par e-mail → (réutilisation proposée) → méthode
 * → résultat. Chaque POST se termine par une redirection 303 (pas de renvoi de formulaire).
 */
final class HostedPageController extends Controller
{
    public const EMBED_MODES = ['modal', 'popup', 'iframe'];

    /** Messages ponctuels affichés après une redirection (liste blanche, clés littérales). */
    private const NOTICES = [
        'code_sent' => ['module.notice.code_sent', 'info'],
        'code_generated' => ['module.notice.code_generated', 'info'],
        VerificationService::CODE_INVALID => ['module.notice.code_invalid', 'error'],
        VerificationService::CODE_EXPIRED => ['module.notice.code_expired', 'error'],
        'code_resend_wait' => ['module.notice.code_resend_wait', 'error'],
        'code_send_limit' => ['module.notice.code_send_limit', 'error'],
        VerificationService::CODE_THROTTLED => ['module.notice.throttled', 'error'],
        'consent_required' => ['module.notice.consent_required', 'error'],
        'method_invalid' => ['module.notice.method_invalid', 'error'],
        'shared_unavailable' => ['module.notice.shared_unavailable', 'error'],
    ];

    public function show(Request $request): Response
    {
        [$project, $session, $service] = $this->load($request);
        $locale = $this->locale($request, $session);
        $embed = $this->embed($request, $project);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $status = $session->effectiveStatus($now);
        $data = [
            'pageTitle' => __('module.page.title'),
            'project' => $project,
            'session' => $session,
            'embed' => $embed,
            'livemode' => $session->livemode,
            'state' => $this->pageToken()->issue($session->publicId, time()),
            'actionBase' => '/s/' . $session->publicId,
            'query' => $this->stateQuery($embed, $locale),
            'notice' => self::NOTICES[$request->query('notice')] ?? null,
            'languageLinks' => $this->languageLinks($session, $embed),
        ];

        if ($status !== VerificationSession::PENDING) {
            $result = $service->sessionResult($session);
            $hasReturn = $session->returnUrl !== null;

            return $this->page('verify/result', [
                ...$data,
                'result' => $result,
                // Intégrée (modale, iframe, popup), la page ne navigue pas : le widget transmet le résultat.
                'returnHref' => $hasReturn && $embed['mode'] === null ? '/s/' . $session->publicId . '/return' . $data['query'] : null,
                // Données transmises à la page parente (widget) : jamais sans origine parente vérifiée.
                'message' => $embed['origin'] !== null ? [
                    'source' => 'veriage',
                    'type' => $result['status'] === 'verified' ? 'completed' : 'failed',
                    'session_id' => $session->publicId,
                    ...array_intersect_key($result, array_flip(['status', 'is_adult', 'verified_at', 'expires_at', 'method'])),
                    'token' => $service->returnToken($project, $session),
                ] : null,
            ]);
        }
        if ($session->consentAt === null) {
            return $this->page('verify/consent', $data);
        }
        if ($session->emailVerifiedAt === null) {
            return $this->page('verify/code', [
                ...$data,
                'maskedEmail' => $service->maskedEmail($session),
                'sandboxCode' => $service->sandboxCode($session),
                'minutes' => intdiv((int) $this->app->config->get('verification.email_code.ttl'), 60),
            ]);
        }
        if ($request->query('shared') !== 'declined' && ($candidate = $service->sharedCandidate($project, $session)) !== null) {
            return $this->page('verify/shared', [
                ...$data,
                'sharedVerifiedAt' => $this->app->formatter()->date(new \DateTimeImmutable((string) $candidate['verified_at'], new \DateTimeZone('UTC')), $locale),
            ]);
        }

        return $this->page('verify/method', [...$data, 'methods' => $service->availableMethods($project, $session)]);
    }

    public function consent(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        if ($request->input('consent') !== '1') {
            return $this->back($request, $session, 'consent_required');
        }
        $error = $service->consent($project, $session, $this->locale($request, $session), $request->ip());

        return $this->back($request, $session, $error ?? ($session->livemode ? 'code_sent' : 'code_generated'));
    }

    public function code(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        $result = $service->verifyCode($project, $session, $request->input('code'), $request->ip());

        return $this->back($request, $session, $result === VerificationService::CODE_OK || $result === VerificationService::CODE_LOCKED ? null : $result);
    }

    public function resendCode(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        $error = $service->sendCode($project, $session, $this->locale($request, $session), $request->ip());

        return $this->back($request, $session, $error ?? ($session->livemode ? 'code_sent' : 'code_generated'));
    }

    public function shared(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        if ($request->input('choice') === 'accept') {
            return $this->back($request, $session, $service->acceptShared($project, $session, $request->ip()) ? null : 'shared_unavailable');
        }

        return $this->back($request, $session, null, ['shared' => 'declined']);
    }

    public function method(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        $input = [];
        foreach (['outcome'] as $field) {
            $input[$field] = $request->input($field);
        }
        try {
            $service->runMethod($project, $session, (string) $request->routeParam('method'), $input, $request->input('share') === '1', $request->ip());
        } catch (\InvalidArgumentException) {
            return $this->back($request, $session, 'method_invalid', ['shared' => 'declined']);
        }

        return $this->back($request, $session, null);
    }

    /** « Retour sur le site » : redirection vers return_url avec un jeton frais (JWT, 5 min). */
    public function returnToClient(Request $request): Response
    {
        [$project, $session, $service] = $this->load($request);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $url = $session->effectiveStatus($now) === VerificationSession::PENDING ? null : $service->returnUrl($project, $session);
        if ($url === null) {
            return $this->back($request, $session, null);
        }

        return Response::redirectAway($url);
    }

    /** @return array{0: Project, 1: VerificationSession, 2: VerificationService} */
    private function load(Request $request): array
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
    private function loadForPost(Request $request): array
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
    private function page(string $template, array $data): Response
    {
        $data['noindex'] = true;

        return Response::html($this->app->view()->render($template, $data, 'layouts/verify'));
    }

    /** @param array<string, string> $extra */
    private function back(Request $request, VerificationSession $session, ?string $notice, array $extra = []): Response
    {
        $embed = ['mode' => $request->query('embed'), 'origin' => $request->query('origin')];
        $params = array_filter([
            'lang' => $request->query('lang'),
            'embed' => in_array($embed['mode'], self::EMBED_MODES, true) ? $embed['mode'] : '',
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
    private function embed(Request $request, Project $project): array
    {
        $mode = $request->query('embed');
        $origin = $request->query('origin');
        $mode = in_array($mode, self::EMBED_MODES, true) ? $mode : null;
        $origin = $mode !== null && $origin !== '' && strlen($origin) <= 255 && UrlGuard::originAllowed($origin, $project->allowedOrigins)
            && preg_match('#^https?://[a-z0-9.\-:\[\]]+$#D', $origin) === 1 ? $origin : null;

        return ['mode' => $mode, 'origin' => $origin];
    }

    /** Paramètres d'URL à conserver d'une étape à l'autre. @param array{mode: ?string, origin: ?string} $embed */
    private function stateQuery(array $embed, string $locale): string
    {
        $params = array_filter(['lang' => $locale, 'embed' => $embed['mode'], 'origin' => $embed['origin']], static fn (?string $v): bool => $v !== null);

        return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array{mode: ?string, origin: ?string} $embed
     * @return array<string, string> langue => URL
     */
    private function languageLinks(VerificationSession $session, array $embed): array
    {
        $links = [];
        foreach ($this->app->translator()->enabled() as $code) {
            $links[$code] = '/s/' . $session->publicId . $this->stateQuery($embed, $code);
        }

        return $links;
    }

    /** Langue : ?lang= > langue demandée à la création de la session > Accept-Language > anglais. */
    private function locale(Request $request, VerificationSession $session): string
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

    private function pageToken(): PageToken
    {
        return new PageToken($this->app->crypto()->deriveKey('hosted-page'));
    }
}
