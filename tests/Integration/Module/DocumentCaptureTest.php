<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Core\Response;
use App\Models\ManualReviewRepository;
use App\Services\ProjectAdmin;
use App\Verification\VerificationService;
use Tests\Support\FakeBiometricsService;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;
use Tests\Unit\CaptureTest;

/**
 * Méthode « pièce d'identité + visage » sur la page hébergée, avec un FAUX microservice (signatures
 * vérifiées comme le vrai) : consentement biométrique, défis tirés par le serveur, envoi chiffré à usage
 * unique, décision et seuils, revue manuelle, limites et garde-fous (rejeu, délai, tirages), rien sur disque.
 * Le test de bout en bout avec le VRAI microservice Python est BiometricsServiceTest.
 */
final class DocumentCaptureTest extends ModuleTestCase
{
    private const SECRET = 'integration-secret-0123456789abcdef-012345';
    private FakeBiometricsService $service;
    private HttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FakeBiometricsService(self::SECRET);
        TestApplication::$biometrics = $this->service;
        $this->client = self::client();
    }

    protected function tearDown(): void
    {
        TestApplication::$biometrics = null;
        parent::tearDown();
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $row = $this->app->db()->fetchOne($sql, $params);

        return $row === null ? null : array_values($row)[0];
    }

    /** @param array<string, mixed> $overrides */
    private static function client(array $overrides = []): HttpClient
    {
        $host = (string) \App\Core\HostMap::authority((string) TestApplication::boot()->config->get('app.verify_url'));

        return new HttpClient('203.0.113.10', $host, [
            'biometrics.enabled' => true,
            'biometrics.secret' => self::SECRET,
            // Rythme accéléré (le délai minimal côté serveur en dépend).
            'biometrics.challenge.neutral_ms' => 10,
            'biometrics.challenge.step_ms' => 10,
            ...$overrides,
        ]);
    }

    /** Session jusqu'à l'adresse confirmée ; renvoie son identifiant. */
    private function sessionReady(string $key, string $email = 'doc@example.be', array $extra = []): string
    {
        $id = $this->newSession($key, $email, $extra)['session_id'];
        $base = '/s/' . $id;
        $page = $this->client->get($base);
        $this->client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $this->client->get($base);
        self::assertSame(303, $this->client->post($base . '/code', ['_state' => self::state($page), 'code' => self::code($page)])->status());

        return $id;
    }

    private function consentBiometrics(string $id, bool $share = false): Response
    {
        $page = $this->client->get('/s/' . $id . '/document');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('name="consent"', $page->body(), 'écran de consentement biométrique dédié');
        $post = $this->client->post('/s/' . $id . '/document/consent', ['_state' => self::state($page), 'consent' => '1'] + ($share ? ['share' => '1'] : []));
        self::assertSame(303, $post->status());
        self::assertStringEndsWith('/s/' . $id . '/document', (string) $post->header('Location'));

        return $this->client->get('/s/' . $id . '/document');
    }

    /** @return array<string, mixed> réponse de /document/start */
    private function start(string $id, Response $capturePage): array
    {
        $state = self::captureState($capturePage);
        $response = $this->client->post('/s/' . $id . '/document/start', ['_state' => $state]);
        self::assertSame(200, $response->status(), $response->body());

        return [...self::body($response), '_state' => $state];
    }

    private static function captureState(Response $page): string
    {
        self::assertSame(1, preg_match('/data-state="([^"]+)"/', $page->body(), $m));

        return $m[1];
    }

    /** Charge utile chiffrée comme le fait le navigateur (WebCrypto AES-GCM : IV || chiffré || étiquette). */
    private static function encrypt(array $capture, array $payload): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', base64_decode((string) $capture['key'], true), OPENSSL_RAW_DATA, $iv, $tag, (string) $capture['aad'], 16);

        return $iv . $ciphertext . $tag;
    }

    /** @return array<string, mixed> */
    private static function payload(int $steps = 4, string $type = 'id_card'): array
    {
        $doc = base64_encode(CaptureTest::jpeg(640, 480));
        $frame = base64_encode(CaptureTest::jpeg(160, 120));
        $frames = [];
        foreach (range(0, $steps) as $step) {
            $frames[] = ['t' => $step * 10, 'step' => $step, 'image' => $frame];
        }

        return ['document_type' => $type, 'front' => $doc, 'back' => $type === 'id_card' ? $doc : null, 'frames' => $frames];
    }

    private function submit(string $id, array $capture, string $body, ?string $captureId = null): Response
    {
        return $this->client->request('POST', '/s/' . $id . '/document/submit', [], [
            'Content-Type' => 'application/octet-stream',
            'X-VeriAge-State' => (string) $capture['_state'],
            'X-VeriAge-Capture' => $captureId ?? (string) $capture['capture_id'],
        ], $body);
    }

    private function fullCapture(string $id): Response
    {
        $capture = $this->start($id, $this->consentBiometrics($id));
        usleep(40_000);
        $response = $this->submit($id, $capture, self::encrypt($capture, self::payload(count($capture['challenge']))));
        self::assertSame(200, $response->status(), $response->body());

        return $this->client->get((string) self::body($response)['redirect']);
    }

    public function testFullCaptureFlowVerifiesTheAgeAndReturnsOnlyTheResult(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $id = $this->sessionReady($p['keys']['test']);
        $methods = $this->client->get('/s/' . $id . '?shared=declined')->body();
        self::assertStringContainsString('/s/' . $id . '/document', $methods, 'méthode proposée');

        $capturePage = $this->consentBiometrics($id);
        self::assertStringContainsString('js/capture.js', $capturePage->body());
        self::assertStringNotContainsString('<script>', $capturePage->body(), 'aucun script inline (CSP)');
        $capture = $this->start($id, $capturePage);
        self::assertCount(4, $capture['challenge'], 'défis tirés par le serveur');
        self::assertSame([], array_diff($capture['challenge'], ['turn_left', 'turn_right', 'blink', 'open_mouth']));
        for ($i = 1; $i < 4; $i++) {
            self::assertNotSame($capture['challenge'][$i - 1], $capture['challenge'][$i], 'jamais deux défis identiques de suite');
        }
        self::assertSame(32, strlen((string) base64_decode((string) $capture['key'], true)));

        usleep(40_000);
        $payload = self::payload();
        $response = $this->submit($id, $capture, self::encrypt($capture, $payload));
        self::assertSame(200, $response->status(), $response->body());
        $result = $this->client->get((string) self::body($response)['redirect']);
        self::assertStringContainsString('data-result-status="verified"', $result->body());

        // Le microservice a reçu les défis DU SERVEUR, la date de Bruxelles et les images, rien d'autre.
        self::assertSame($capture['challenge'], $this->service->lastRequest['selfie']['challenge']);
        self::assertSame(['reference_date', 'document', 'selfie', 'liveness'], array_keys($this->service->lastRequest));
        self::assertSame($payload['front'], $this->service->lastRequest['document']['front']);

        $status = self::body($this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=doc@example.be'));
        self::assertSame(['verified', true, 'id_document_face'], [$status['status'], $status['is_adult'], $status['method']]);
        // Aucune image ni donnée d'identité en base (sessions, vérifications, audit, livraisons), ni dans les journaux.
        $dump = json_encode([
            $this->app->db()->fetchAll('SELECT * FROM verification_sessions'),
            $this->app->db()->fetchAll('SELECT * FROM audit_log'),
            $this->app->db()->fetchAll('SELECT id, event_type, status FROM webhook_deliveries'),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertStringNotContainsString(substr($payload['front'], 0, 40), (string) $dump);
        self::assertStringNotContainsString(substr($payload['front'], 0, 40), implode("\n", TestApplication::logLines()));
        self::assertStringContainsString('verification.biometric_consent', (string) $dump);
    }

    public function testDecisionsFromTheServiceResponse(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $cases = [
            'minor@example.be' => [FakeBiometricsService::result(age: 16), 'data-result-status="verified"', null],
            'expired@example.be' => [FakeBiometricsService::result(expired: true, reasons: ['document_expired']), 'data-failure-reason="document_expired"', 'document_expired'],
            'mrz@example.be' => [FakeBiometricsService::result(age: null, expired: null, mrz: false, reasons: ['mrz_not_found']), 'data-failure-reason="document_unreadable"', 'document_unreadable'],
            'alive@example.be' => [FakeBiometricsService::result(liveness: false, reasons: ['liveness_challenge_failed']), 'data-failure-reason="liveness_failed"', 'liveness_failed'],
            'other@example.be' => [FakeBiometricsService::result(score: 0.12), 'data-failure-reason="face_mismatch"', 'face_mismatch'],
        ];
        foreach ($cases as $email => [$response, $marker, $reason]) {
            $this->service->response = $response;
            $page = $this->fullCapture($this->sessionReady($key, $email));
            self::assertStringContainsString($marker, $page->body(), $email);
            $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=' . rawurlencode($email)));
            if ($reason === null) {
                self::assertSame(['verified', false], [$status['status'], $status['is_adult']], 'mineur (16 < 18)');
            } else {
                self::assertSame(['failed', $reason], [$status['status'], $status['failure_reason']], $email);
            }
        }
    }

    /**
     * Panne technique (service arrêté, occupé, réponse forgée ou hors contrat) : aucune décision. La
     * session reste ouverte (ni échec, ni webhook, ni blocage de l'adresse) et l'on peut recommencer.
     */
    public function testServiceOutageOrForgedResponseLeavesTheSessionOpen(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $key = $p['keys']['test'];
        $id = $this->sessionReady($key, 'down@example.be');
        $capturePage = $this->consentBiometrics($id);
        $faults = [
            'down' => fn () => $this->service->down = true,
            'forged' => fn () => $this->service->responseSecret = 'attacker-secret-0123456789abcdef-012345678',
            'leak' => fn () => $this->service->response = [...FakeBiometricsService::result(), 'name' => 'SPECIMEN'],
        ];
        foreach ($faults as $name => $fault) {
            $this->service->down = false;
            $this->service->responseSecret = null;
            $this->service->response = FakeBiometricsService::result();
            $fault();
            $capture = $this->start($id, $capturePage);
            usleep(40_000);
            $response = $this->submit($id, $capture, self::encrypt($capture, self::payload(count($capture['challenge']))));
            self::assertSame(503, $response->status(), $name);
            self::assertSame('biometrics_unavailable', self::body($response)['error'], $name);
            $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=down@example.be'));
            self::assertSame('pending', $status['status'], $name . ' : session laissée ouverte');
        }
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM webhook_deliveries'), 'aucun webhook pour une panne');
        self::assertStringNotContainsString('SPECIMEN', implode("\n", TestApplication::logLines()));
        self::assertStringContainsString('biometrics_call_failed', implode("\n", TestApplication::logLines()));
    }

    public function testCaptureSucceedsAfterATransientOutage(): void
    {
        $p = $this->createProject();
        $id = $this->sessionReady($p['keys']['test'], 'retry@example.be');
        $capturePage = $this->consentBiometrics($id);
        $this->service->down = true;
        $capture = $this->start($id, $capturePage);
        usleep(40_000);
        self::assertSame(503, $this->submit($id, $capture, self::encrypt($capture, self::payload(count($capture['challenge']))))->status());
        $this->service->down = false;
        $capture = $this->start($id, $capturePage);
        usleep(40_000);
        $response = $this->submit($id, $capture, self::encrypt($capture, self::payload(count($capture['challenge']))));
        self::assertSame(200, $response->status(), $response->body());
        self::assertStringContainsString('data-result-status="verified"', $this->client->get((string) self::body($response)['redirect'])->body());
    }

    public function testBelowThresholdAlwaysFailsManualReviewIsDisabled(): void
    {
        // Audit phase 3, E3 : sans image, la revue ne vérifie rien ; sous le seuil, la vérification échoue.
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        try {
            ProjectAdmin::fromApplication($this->app)->setBelowThreshold($p['project'], 'review');
            self::fail('Revue manuelle acceptée.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('désactivée', $e->getMessage());
        }
        // Même un projet resté en « review » en base (avant la migration 0020) échoue sous le seuil.
        $this->app->db()->execute("UPDATE projects SET below_threshold = 'review' WHERE id = ?", [$p['project']->id]);
        $this->service->response = FakeBiometricsService::result(age: 25, score: 0.35);
        $page = $this->fullCapture($this->sessionReady($p['keys']['test'], 'review@example.be'));
        self::assertStringContainsString('data-failure-reason="face_mismatch"', $page->body());
        self::assertNull($this->value('SELECT id FROM manual_reviews'));
        $status = self::body($this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=review@example.be'));
        self::assertSame(['failed', 'face_mismatch'], [$status['status'], $status['failure_reason']]);
        self::assertArrayNotHasKey('review', $status);
    }

    public function testInconsistentOrUnsupportedDocumentsFail(): void
    {
        $p = $this->createProject();
        foreach ([
            'a@example.be' => [['document_not_detected'], 'document_inconsistent'],
            'b@example.be' => [['document_sides_mismatch'], 'document_inconsistent'],
            'u@example.be' => [['document_unsupported'], 'document_unsupported'],
        ] as $email => [$reasons, $expected]) {
            $unsupported = $expected === 'document_unsupported';
            $this->service->response = FakeBiometricsService::result(age: $unsupported ? null : 51, expired: $unsupported ? null : false,
                score: null, mrz: !$unsupported, reasons: $reasons);
            $page = $this->fullCapture($this->sessionReady($p['keys']['test'], $email));
            self::assertStringContainsString('data-failure-reason="' . $expected . '"', $page->body(), $email);
        }
    }

    public function testCaptureIsSingleUseBoundAndTimed(): void
    {
        $p = $this->createProject();
        $id = $this->sessionReady($p['keys']['test']);
        $capture = $this->start($id, $this->consentBiometrics($id));
        $body = self::encrypt($capture, self::payload());

        // Trop rapide pour le rythme imposé (délai réel du serveur).
        $slow = self::client(['biometrics.challenge.neutral_ms' => 60_000]);
        $response = $slow->request('POST', '/s/' . $id . '/document/submit', [], ['Content-Type' => 'application/octet-stream',
            'X-VeriAge-State' => $capture['_state'], 'X-VeriAge-Capture' => $capture['capture_id']], $body);
        self::assertSame(['capture_too_fast', 422], [self::body($response)['error'], $response->status()]);
        // Le défi a été consommé : un nouvel envoi du même corps est refusé (rejeu).
        self::assertSame('capture_expired', self::body($this->submit($id, $capture, $body))['error']);
        self::assertSame(0, $this->service->calls, 'rien n\'a été relayé');

        // Corps chiffré pour une autre capture, altéré, ou sans jeton de page.
        $second = $this->start($id, $this->client->get('/s/' . $id . '/document'));
        usleep(40_000);
        self::assertSame('capture_invalid', self::body($this->submit($id, $second, self::encrypt($capture, self::payload())))['error']);
        $third = $this->start($id, $this->client->get('/s/' . $id . '/document'));
        usleep(40_000);
        $noState = $this->client->request('POST', '/s/' . $id . '/document/submit', [], ['Content-Type' => 'application/octet-stream', 'X-VeriAge-Capture' => $third['capture_id']], 'x');
        self::assertSame(419, $noState->status());
        self::assertSame(0, $this->service->calls);

        // Tirages épuisés (3) : la session échoue, pour empêcher de tirer jusqu'à l'ordre voulu.
        $refused = $this->client->post('/s/' . $id . '/document/start', ['_state' => $capture['_state']]);
        self::assertSame(['capture_attempts_exceeded', 429], [self::body($refused)['error'], $refused->status()]);
        self::assertSame('capture_attempts_exceeded', self::body($this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=doc@example.be'))['failure_reason']);
    }

    public function testPayloadLimitsAndTimestampConsistency(): void
    {
        $p = $this->createProject();
        $id = $this->sessionReady($p['keys']['test']);
        $capture = $this->start($id, $this->consentBiometrics($id));
        usleep(40_000);
        // Horodatages du navigateur incohérents avec le temps écoulé côté serveur (60 s annoncées).
        $payload = self::payload();
        $payload['frames'][count($payload['frames']) - 1]['t'] = 60_000;
        self::assertSame('capture_invalid', self::body($this->submit($id, $capture, self::encrypt($capture, $payload)))['error']);

        $capture = $this->start($id, $this->client->get('/s/' . $id . '/document'));
        usleep(40_000);
        $big = self::client(['biometrics.capture.max_body_bytes' => 1000]);
        $response = $big->request('POST', '/s/' . $id . '/document/submit', [], ['Content-Type' => 'application/octet-stream',
            'X-VeriAge-State' => $capture['_state'], 'X-VeriAge-Capture' => $capture['capture_id']], self::encrypt($capture, self::payload()));
        self::assertSame(['capture_too_large', 413], [self::body($response)['error'], $response->status()]);

        $capture = $this->start($id, $this->client->get('/s/' . $id . '/document'));
        usleep(40_000);
        $gif = self::payload();
        $gif['front'] = base64_encode('GIF89a' . str_repeat("\0", 64));
        self::assertSame('capture_image_invalid', self::body($this->submit($id, $capture, self::encrypt($capture, $gif)))['error']);
        self::assertSame(0, $this->service->calls);
    }

    public function testBiometricConsentIsRequiredAndStepsAreEnforced(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        // Avant le contrôle de l'adresse : pas d'accès à la capture.
        $early = $this->newSession($key, 'early@example.be')['session_id'];
        self::assertSame(303, $this->client->get('/s/' . $early . '/document')->status());

        $id = $this->sessionReady($key);
        $page = $this->client->get('/s/' . $id . '/document');
        $refused = $this->client->post('/s/' . $id . '/document/consent', ['_state' => self::state($page)]);
        self::assertStringContainsString('notice=consent_required', (string) $refused->header('Location'));
        self::assertSame(409, $this->client->post('/s/' . $id . '/document/start', ['_state' => self::state($page)])->status(), 'pas de défi sans consentement');
        self::assertNull($this->value('SELECT biometric_consent_at FROM verification_sessions WHERE public_id = ?', [$id]));

        // Méthode désactivée (service non configuré) : ni proposée, ni accessible.
        $off = self::client(['biometrics.enabled' => false]);
        self::assertStringNotContainsString('/document', $off->get('/s/' . $id . '?shared=declined')->body());
        self::assertSame(303, $off->get('/s/' . $id . '/document')->status());
    }

    public function testShareOptInChosenOnTheBiometricConsentScreen(): void
    {
        $p = $this->createProject();
        $id = $this->sessionReady($p['keys']['test'], 'share@example.be');
        $capture = $this->start($id, $this->consentBiometrics($id, share: true));
        usleep(40_000);
        $this->submit($id, $capture, self::encrypt($capture, self::payload()));
        self::assertNotNull($this->value('SELECT shared_hash FROM verifications'), 'preuve partageable, sur consentement');
    }

    public function testConfirmPageRedirectsWhenNothingIsToBeConfirmed(): void
    {
        // Ré-audit phase 2, N1 : /confirm d'une session close ou déjà confirmée → page de la session.
        $p = $this->createProject();
        $id = $this->sessionReady($p['keys']['test']);
        $response = $this->client->get('/s/' . $id . '/confirm?token=' . str_repeat('A', 32));
        self::assertSame(303, $response->status());
        self::assertStringStartsWith('/s/' . $id, (string) $response->header('Location'));
    }

    public function testLastCompletedSessionIsExposedWhenNoResultIsReusable(): void
    {
        // Ré-audit phase 2, N2 : validité négative 0 h → « not_verified », mais la dernière session est visible.
        $admin = ProjectAdmin::fromApplication($this->app);
        $created = $admin->create($admin->createAccount('Zero'), 'Zero', [self::ORIGIN], negativeTtlHours: 0);
        $key = $created['keys']['test'];
        $session = $this->newSession($key, 'minor0@example.be');
        $this->completeFlow($session['session_id'], 'minor');
        $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=minor0@example.be'));
        self::assertSame('not_verified', $status['status']);
        self::assertSame(['session_id' => $session['session_id'], 'status' => 'verified', 'is_adult' => false], array_intersect_key($status['last_session'], array_flip(['session_id', 'status', 'is_adult'])));
        self::assertSame('mock', $status['last_session']['method']);
    }
}
