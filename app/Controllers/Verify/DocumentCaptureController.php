<?php

declare(strict_types=1);

namespace App\Controllers\Verify;

use App\Controllers\Controller;
use App\Core\ApiException;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\VerificationSession;
use App\Verification\Biometrics\CaptureCipher;
use App\Verification\Biometrics\CapturePayload;
use App\Verification\Methods\LocalBiometricsProvider;
use App\Verification\PageToken;
use App\Verification\VerificationService;

/**
 * Méthode « pièce d'identité + visage » sur la page hébergée : écran de consentement biométrique
 * (RGPD art. 9), puis capture (recto, verso, selfie avec défis) par le script capture.js.
 *
 * - GET  /s/{id}/document          consentement biométrique, puis page de capture ;
 * - POST /s/{id}/document/consent  consentement explicite (+ réutilisation facultative entre sites) ;
 * - POST /s/{id}/document/start    tire les défis (serveur) et ouvre une capture : identifiant à usage
 *                                  unique et clé de chiffrement de l'envoi (JSON) ;
 * - POST /s/{id}/document/submit   envoi chiffré (application/octet-stream) : déchiffré et validé en
 *                                  mémoire, relayé au microservice local, résultat → redirection (JSON).
 * Les images ne sont jamais écrites sur disque par l'application, ni journalisées.
 */
final class DocumentCaptureController extends Controller
{
    use HostedPageSupport;

    private const METHOD = LocalBiometricsProvider::ID;
    private const CAPTURE_ID = '/^[A-Za-z0-9]{24}$/D';

    public function show(Request $request): Response
    {
        [$project, $session, $service] = $this->load($request);
        if (!$this->allowed($project, $session, $service)) {
            return $this->back($request, $session, null, ['shared' => 'declined']);
        }
        $locale = $this->locale($request, $session);
        $embed = $this->embed($request, $project);
        $data = [
            'pageTitle' => __('module.page.title'),
            'stepTitle' => __('module.steps.method'),
            'project' => $project,
            'session' => $session,
            'embed' => $embed,
            'livemode' => $session->livemode,
            'state' => $this->pageToken()->issue($session->publicId, time()),
            'actionBase' => '/s/' . $session->publicId,
            'query' => $this->stateQuery($embed, $locale),
            'notice' => $request->query('notice') === 'consent_required' ? ['module.notice.consent_required', 'error'] : null,
            'languageLinks' => $this->documentLanguageLinks($session, $embed),
            'biometricPage' => true,
        ];
        if ($session->biometricConsentAt === null) {
            return $this->page('verify/biometric_consent', [...$data, 'stepTitle' => __('module.biometric.consent_title')]);
        }
        /** @var array<string, int> $challenge */
        $challenge = $this->app->config->get('biometrics.challenge');
        /** @var array<string, int> $capture */
        $capture = $this->app->config->get('biometrics.capture');

        return $this->page('verify/capture', [...$data, 'stepTitle' => __('module.capture.title'), 'scripts' => ['js/capture.js'], 'settings' => [
            'steps' => $challenge['steps'],
            'neutral_ms' => $challenge['neutral_ms'],
            'step_ms' => $challenge['step_ms'],
            'frame_interval_ms' => $challenge['frame_interval_ms'],
            'max_frames' => $capture['max_frames'],
            'doc_max_bytes' => $capture['doc_max_bytes'],
            'frame_max_bytes' => $capture['frame_max_bytes'],
            'attempts_left' => $this->app->challenges()->remainingAttempts($session->publicId),
        ]]);
    }

    public function consent(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        if (!$this->allowed($project, $session, $service)) {
            return $this->back($request, $session, null);
        }
        if ($request->input('consent') !== '1') {
            return $this->documentRedirect($request, $session, 'consent_required');
        }
        $service->recordBiometricConsent($project, $session, $request->input('share') === '1', $request->ip());

        return $this->documentRedirect($request, $session, null);
    }

    public function start(Request $request): Response
    {
        [$project, $session, $service] = $this->loadForPost($request);
        if (!$this->allowed($project, $session, $service) || $session->biometricConsentAt === null) {
            return $this->error($request, $session, 'capture_not_allowed', 409);
        }
        $steps = (int) $this->app->config->get('biometrics.challenge.steps');
        $record = $this->app->challenges()->issue($session->publicId, $steps, self::nowMs());
        if ($record === null) {
            // Tirages épuisés (anti-« tirage jusqu'au bon ordre ») : la session échoue.
            $service->failCapture($project, $session, 'capture_attempts_exceeded', $request->ip());

            return $this->error($request, $session, 'capture_attempts_exceeded', 429);
        }

        return Response::json([
            'capture_id' => $record['capture_id'],
            'challenge' => $record['steps'],
            'key' => base64_encode($this->app->captureCipher()->key($session->publicId, $record['capture_id'])),
            'aad' => CaptureCipher::aad($session->publicId, $record['capture_id']),
            'expires_in' => (int) $this->app->config->get('biometrics.challenge.ttl'),
            'attempts_left' => $this->app->challenges()->remainingAttempts($session->publicId),
        ]);
    }

    public function submit(Request $request): Response
    {
        [$project, $session, $service] = $this->load($request);
        // Corps binaire : le jeton de page et l'identifiant de capture voyagent dans des en-têtes.
        if (!$this->pageToken()->validate((string) $request->header('X-VeriAge-State'), $session->publicId, time())) {
            throw new HttpException(419);
        }
        if (!$this->allowed($project, $session, $service) || $session->biometricConsentAt === null) {
            return $this->error($request, $session, 'capture_not_allowed', 409);
        }
        $captureId = (string) $request->header('X-VeriAge-Capture');
        $record = preg_match(self::CAPTURE_ID, $captureId) === 1 ? $this->app->challenges()->consume($session->publicId, $captureId) : null;
        if ($record === null) {
            return $this->error($request, $session, 'capture_expired', 409);
        }
        /** @var array{neutral_ms: int, step_ms: int} $timing */
        $timing = $this->app->config->get('biometrics.challenge');
        $elapsed = self::nowMs() - $record['issued_ms'];
        // Délai minimal : la séquence ne peut pas être plus rapide que le rythme imposé (tolérance 20 %).
        if ($elapsed < (int) (0.8 * ($timing['neutral_ms'] + count($record['steps']) * $timing['step_ms']))) {
            return $this->error($request, $session, 'capture_too_fast', 422);
        }
        /** @var array{max_body_bytes: int, doc_max_bytes: int, doc_max_side: int, doc_min_side: int, frame_max_bytes: int, frame_max_side: int, max_frames: int} $limits */
        $limits = $this->app->config->get('biometrics.capture');
        try {
            $plaintext = $this->app->captureCipher()->decrypt($request->binaryBody($limits['max_body_bytes']), $session->publicId, $captureId);
            $capture = CapturePayload::fromJson($plaintext, count($record['steps']), $limits);
            unset($plaintext);
        } catch (ApiException $e) {
            return $this->error($request, $session, $e->status() === 413 ? 'capture_too_large' : 'capture_invalid', $e->status());
        } catch (\InvalidArgumentException $e) {
            return $this->error($request, $session, $e->getMessage(), 422);
        }
        // Horodatages du navigateur incohérents avec le temps réellement écoulé côté serveur.
        if ($capture->spanMs() > $elapsed + 2000) {
            return $this->error($request, $session, 'capture_invalid', 422);
        }
        $service->runCapture($project, $session, self::METHOD, $capture, $record['steps'], $request->ip());

        return Response::json(['redirect' => $this->sessionUrl($request, $session)]);
    }

    private function allowed(Project $project, VerificationSession $session, VerificationService $service): bool
    {
        if (!$session->isOpen(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) || $session->consentAt === null
            || $session->emailVerifiedAt === null || $session->reviewAt !== null) {
            return false;
        }
        foreach ($service->availableMethods($project, $session) as $method) {
            if ($method->id() === self::METHOD) {
                return true;
            }
        }

        return false;
    }

    private function error(Request $request, VerificationSession $session, string $code, int $status): Response
    {
        return Response::json(['error' => $code, 'redirect' => $this->sessionUrl($request, $session)], $status);
    }

    private function sessionUrl(Request $request, VerificationSession $session): string
    {
        return (string) $this->back($request, $session, null)->header('Location');
    }

    private function documentRedirect(Request $request, VerificationSession $session, ?string $notice): Response
    {
        $location = (string) $this->back($request, $session, $notice)->header('Location');
        [$path, $query] = array_pad(explode('?', $location, 2), 2, '');

        return Response::redirect($path . '/document' . ($query === '' ? '' : '?' . $query), 303);
    }

    /**
     * @param array{mode: ?string, origin: ?string} $embed
     * @return array<string, string>
     */
    private function documentLanguageLinks(VerificationSession $session, array $embed): array
    {
        $links = [];
        foreach ($this->languageLinks($session, $embed) as $code => $href) {
            [$path, $query] = array_pad(explode('?', $href, 2), 2, '');
            $links[$code] = $path . '/document' . ($query === '' ? '' : '?' . $query);
        }

        return $links;
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
