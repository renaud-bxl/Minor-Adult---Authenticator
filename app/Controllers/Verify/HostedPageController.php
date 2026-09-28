<?php

declare(strict_types=1);

namespace App\Controllers\Verify;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\VerificationSession;
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
    use HostedPageSupport;

    public const EMBED_MODES = ['modal', 'popup', 'iframe'];

    /** Messages ponctuels affichés après une redirection (liste blanche, clés littérales). */
    private const NOTICES = [
        'code_sent' => ['module.notice.code_sent', 'info'],
        'code_generated' => ['module.notice.code_generated', 'info'],
        'link_sent' => ['module.notice.link_sent', 'info'],
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
            'stepTitle' => null,
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
            // Jeton de retour : seulement dans la courte fenêtre qui suit la fin de la session.
            $tokenAllowed = $service->canIssueReturnToken($session);
            $hasReturn = $session->returnUrl !== null && $tokenAllowed;

            return $this->page('verify/result', [
                ...$data,
                'biometricPage' => $session->method === \App\Verification\Methods\LocalBiometricsProvider::ID,
                'stepTitle' => __('module.steps.result'),
                'result' => $result,
                // Intégrée (modale, iframe, popup), la page ne navigue pas : le widget transmet le résultat.
                'returnHref' => $hasReturn && $embed['mode'] === null ? '/s/' . $session->publicId . '/return' . $data['query'] : null,
                // Rien pour revenir au site (popup, ou redirection sans return_url) : inviter à fermer.
                'canClose' => $embed['mode'] === 'popup' || ($embed['mode'] === null && !$hasReturn),
                // Données transmises à la page parente (widget) : jamais sans origine parente vérifiée.
                'message' => $embed['origin'] !== null ? [
                    'source' => 'veriage',
                    'type' => $result['status'] === 'verified' ? 'completed' : 'failed',
                    'session_id' => $session->publicId,
                    ...array_intersect_key($result, array_flip(['status', 'is_adult', 'min_age', 'verified_at', 'expires_at', 'method'])),
                    'token' => $tokenAllowed ? $service->returnToken($project, $session) : null,
                ] : null,
            ]);
        }
        if ($session->inReview($now)) {
            return $this->page('verify/review', [...$data, 'stepTitle' => __('module.steps.result'), 'biometricPage' => true]);
        }
        if ($session->consentAt === null) {
            return $this->page('verify/consent', [...$data, 'stepTitle' => __('module.steps.consent')]);
        }
        if ($session->emailVerifiedAt === null) {
            // Erreur de saisie : affichée au plus près du champ (aria-describedby, aria-invalid).
            $fieldError = in_array($request->query('notice'), [VerificationService::CODE_INVALID, VerificationService::CODE_EXPIRED], true)
                ? $data['notice'] : null;

            return $this->page($session->proofKind === 'link' ? 'verify/link' : 'verify/code', [
                ...$data,
                'stepTitle' => __('module.steps.email'),
                'notice' => $fieldError === null ? $data['notice'] : null,
                'fieldError' => $fieldError,
                'maskedEmail' => $service->maskedEmail($session),
                'sandboxCode' => $service->sandboxCode($session),
                'minutes' => intdiv((int) $this->app->config->get('verification.email_code.ttl'), 60),
            ]);
        }
        if ($request->query('shared') !== 'declined' && ($candidate = $service->sharedCandidate($project, $session)) !== null) {
            return $this->page('verify/shared', [
                ...$data,
                'stepTitle' => __('module.steps.method'),
                'sharedVerifiedAt' => $this->app->formatter()->date(new \DateTimeImmutable((string) $candidate['verified_at'], new \DateTimeZone('UTC')), $locale),
            ]);
        }

        return $this->page('verify/method', [...$data, 'stepTitle' => __('module.steps.method'), 'methods' => $service->availableMethods($project, $session)]);
    }

    /**
     * Lien à usage unique reçu par e-mail (preuve renforcée). Une page de confirmation en POST, et non
     * une validation au GET : les scanners de liens des messageries ne consomment pas le lien.
     */
    public function confirm(Request $request): Response
    {
        [$project, $session] = $this->load($request);
        // Session close, adresse déjà confirmée ou preuve par code : rien à confirmer ici (ré-audit phase 2, N1).
        if (!$session->isOpen(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) || $session->emailVerifiedAt !== null || $session->proofKind !== 'link') {
            return $this->back($request, $session, null);
        }
        $embed = $this->embed($request, $project);
        $locale = $this->locale($request, $session);

        return $this->page('verify/confirm', [
            'pageTitle' => __('module.page.title'),
            'stepTitle' => __('module.steps.email'),
            'project' => $project,
            'session' => $session,
            'embed' => $embed,
            'livemode' => $session->livemode,
            'state' => $this->pageToken()->issue($session->publicId, time()),
            'actionBase' => '/s/' . $session->publicId,
            'query' => $this->stateQuery($embed, $locale),
            'token' => preg_match('/^[A-Za-z0-9]{32}$/D', $request->query('token')) === 1 ? $request->query('token') : '',
            'languageLinks' => [],
        ]);
    }

    public function consent(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        if ($request->input('consent') !== '1') {
            return $this->back($request, $session, 'consent_required');
        }
        $error = $service->consent($project, $session, $this->locale($request, $session), $request->ip());

        return $this->back($request, $session, $error ?? $this->sentNotice($service->refresh($session)));
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

        return $this->back($request, $session, $error ?? $this->sentNotice($service->refresh($session)));
    }

    private function sentNotice(VerificationSession $session): string
    {
        return match (true) {
            !$session->livemode => 'code_generated',
            $session->proofKind === 'link' => 'link_sent',
            default => 'code_sent',
        };
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
}
