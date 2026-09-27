<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Billing\CreditGateInterface;
use App\Core\ApiException;
use App\Core\HostMap;
use App\Core\Request;
use App\Models\ApiKeyRepository;
use App\Models\Project;
use App\Verification\ApiContext;
use App\Verification\VerificationService;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/** Authentification, limitation de débit, cloisonnement entre clients (IDOR), 402, blocage, hôtes. */
final class ApiSecurityTest extends ModuleTestCase
{
    public function testMissingInvalidAndRevokedKeysAre401(): void
    {
        $p = $this->createProject();
        $client = HttpClient::verify();

        $missing = $client->json('GET', '/api/v1/verifications?email=a@b.be');
        self::assertSame(401, $missing->status());
        self::assertSame('Bearer realm="VeriAge"', $missing->header('WWW-Authenticate'));
        self::assertSame('unauthorized', self::body($missing)['error']['code']);

        foreach (['Bearer sk_test_' . str_repeat('A', 40), 'Bearer ' . $p['keys']['test'] . 'x', 'Basic ' . base64_encode('u:p'), $p['keys']['test']] as $header) {
            self::assertSame(401, $client->json('GET', '/api/v1/verifications?email=a@b.be', null, ['Authorization' => $header])->status(), $header);
        }
        // Clé live présentée avec le préfixe test (même secret) : refusée.
        $forged = 'sk_test_' . substr($p['keys']['live'], 8);
        self::assertSame(401, $this->api($forged, 'GET', '/api/v1/verifications?email=a@b.be')->status());

        self::assertSame(200, $this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=a@b.be')->status());
        (new ApiKeyRepository($this->app->db()))->revokeAll($p['project']->id, false);
        self::assertSame(401, $this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=a@b.be')->status());

        // La clé n'est stockée que hachée, jamais en clair.
        $stored = $this->app->db()->fetchAll('SELECT key_hash, last4 FROM api_keys');
        self::assertStringNotContainsString(substr($p['keys']['test'], 8, 20), json_encode(array_map('bin2hex', array_column($stored, 'key_hash'))));
    }

    public function testAuthenticationFailuresAreThrottledPerIp(): void
    {
        $client = HttpClient::verify('198.51.100.7');
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(401, $client->json('GET', '/api/v1/verifications?email=a@b.be', null, ['Authorization' => 'Bearer sk_test_' . str_repeat((string) ($i % 10), 40)])->status());
        }
        $blocked = $client->json('GET', '/api/v1/verifications?email=a@b.be', null, ['Authorization' => 'Bearer sk_test_' . str_repeat('Z', 40)]);
        self::assertSame(429, $blocked->status());
        self::assertSame('rate_limited', self::body($blocked)['error']['code']);
        self::assertGreaterThan(0, (int) $blocked->header('Retry-After'));
        // Une autre IP n'est pas concernée.
        self::assertSame(401, HttpClient::verify('198.51.100.8')->json('GET', '/api/v1/verifications?email=a@b.be')->status());
    }

    public function testRateLimitPerKeyAndPerIp(): void
    {
        $p = $this->createProject();
        $limiter = $this->app->rateLimiter();
        [$max, $window] = $this->app->rateLimit('api_key');
        $keyId = (int) $this->app->db()->fetchOne('SELECT id FROM api_keys WHERE project_id = ? AND livemode = 0', [$p['project']->id])['id'];

        $ok = $this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=a@b.be');
        self::assertSame((string) $max, $ok->header('X-RateLimit-Limit'));
        self::assertSame((string) ($max - 1), $ok->header('X-RateLimit-Remaining'));
        for ($i = 1; $i < $max; $i++) {
            $limiter->attempt('api_key', (string) $keyId, $max, $window);
        }
        $limited = $this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=a@b.be', null, '198.51.100.20');
        self::assertSame(429, $limited->status(), 'quota de la clé épuisé, quelle que soit l\'IP');
        // L'autre clé du projet (live) garde son propre quota.
        self::assertSame(200, $this->api($p['keys']['live'], 'GET', '/api/v1/verifications?email=a@b.be')->status());

        [$maxIp, $windowIp] = $this->app->rateLimit('api_ip');
        for ($i = 0; $i < $maxIp; $i++) {
            $limiter->attempt('api_ip', '198.51.100.30', $maxIp, $windowIp);
        }
        self::assertSame(429, $this->api($p['keys']['live'], 'GET', '/api/v1/verifications?email=a@b.be', null, '198.51.100.30')->status());
    }

    public function testClientsAndModesAreIsolated(): void
    {
        $a = $this->createProject(name: 'Alpha');
        $b = $this->createProject(name: 'Beta');
        $session = $this->newSession($a['keys']['test'], 'shared.person@example.be');
        $this->completeFlow($session['session_id']);

        // Un autre client ne voit rien, n'efface rien (le hash est salé par projet).
        self::assertSame('not_verified', self::body($this->api($b['keys']['test'], 'GET', '/api/v1/verifications?email=shared.person@example.be'))['status']);
        self::assertFalse(self::body($this->api($b['keys']['test'], 'DELETE', '/api/v1/verifications?email=shared.person@example.be'))['deleted']);
        self::assertSame('verified', self::body($this->api($a['keys']['test'], 'GET', '/api/v1/verifications?email=shared.person@example.be'))['status']);

        // La production ne voit pas la sandbox du même client.
        self::assertSame('not_verified', self::body($this->api($a['keys']['live'], 'GET', '/api/v1/verifications?email=shared.person@example.be'))['status']);

        // Et un client ne réutilise pas la vérification d'un autre sans consentement (pas de preuve partagée).
        self::assertSame('pending', $this->newSession($b['keys']['test'], 'shared.person@example.be')['status']);

        $hashes = array_column($this->app->db()->fetchAll('SELECT DISTINCT email_hash FROM verification_sessions'), 'email_hash');
        self::assertCount(2, $hashes, 'même adresse, hash différent par projet');
    }

    public function testInsufficientCreditsIs402ForLiveOnly(): void
    {
        $p = $this->createProject();
        $service = $this->serviceWithGate(new class implements CreditGateInterface {
            public function allowsNewVerification(Project $project, bool $livemode): bool
            {
                return false;
            }
        });
        try {
            $service->createSession(new ApiContext($p['project'], true, 1, 'abcd'), ['email' => 'pay@example.be'], '203.0.113.1');
            self::fail('402 attendu');
        } catch (ApiException $e) {
            self::assertSame(402, $e->status());
            self::assertSame('insufficient_credits', $e->errorCode());
        }
        // La sandbox n'est jamais facturée.
        [$status] = $service->createSession(new ApiContext($p['project'], false, 1, 'abcd'), ['email' => 'pay@example.be'], '203.0.113.1');
        self::assertSame(201, $status);

        $rendered = (new \App\Core\ErrorRenderer($this->app))->render(
            new Request('POST', '/api/v1/sessions', [], [], ['accept-language' => 'fr']),
            new ApiException(402, 'insufficient_credits'),
        );
        self::assertSame(402, $rendered->status());
        self::assertSame('insufficient_credits', self::body($rendered)['error']['code']);
        self::assertStringContainsString('Crédits insuffisants', self::body($rendered)['error']['message']);
    }

    public function testEmailIsLockedAfterRepeatedFailures(): void
    {
        $p = $this->createProject();
        $max = (int) $this->app->config->get('verification.failure_lock.max_failures');
        for ($i = 0; $i < $max; $i++) {
            $session = $this->newSession($p['keys']['test'], 'unlucky@example.be');
            $this->completeFlow($session['session_id'], 'fail');
        }
        $locked = $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'unlucky@example.be']);
        self::assertSame(429, $locked->status());
        self::assertSame('email_locked', self::body($locked)['error']['code']);
        self::assertGreaterThan(3600, (int) $locked->header('Retry-After'));
        // Une autre adresse, ou la même chez un autre client, n'est pas bloquée.
        self::assertSame(201, $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'lucky@example.be'])->status());
        $other = $this->createProject(name: 'Other');
        self::assertSame(201, $this->api($other['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'unlucky@example.be'])->status());
        // L'effacement (droit à l'oubli) lève aussi le blocage.
        $this->api($p['keys']['test'], 'DELETE', '/api/v1/verifications?email=unlucky@example.be');
        self::assertSame(201, $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'unlucky@example.be'])->status());
    }

    public function testSessionCreationIsThrottledPerEmail(): void
    {
        $p = $this->createProject();
        [$max] = $this->app->rateLimit('api_session_email');
        for ($i = 0; $i < $max; $i++) {
            self::assertSame(201, $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'spam@example.be'])->status());
        }
        self::assertSame('rate_limited', self::body($this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'spam@example.be']))['error']['code']);
    }

    public function testRoutingByHost(): void
    {
        $p = $this->createProject();
        // API et page hébergée : uniquement sur verify. ; site : uniquement sur www.
        self::assertSame(404, (new HttpClient())->json('GET', '/api/v1/verifications?email=a@b.be', null, ['Authorization' => 'Bearer ' . $p['keys']['test']])->status());
        self::assertSame(404, HttpClient::verify()->get('/fr/login')->status());
        self::assertSame(404, (new HttpClient('203.0.113.10', 'evil.example'))->get('/fr/')->status());
        self::assertSame(200, (new HttpClient('203.0.113.10', 'WWW.VERIAGE.TEST.'))->get('/fr/')->status(), 'casse et point final');
        self::assertSame(200, (new HttpClient('203.0.113.10', 'www.veriage.test:443'))->get('/fr/')->status(), 'port par défaut');
        $apex = (new HttpClient('203.0.113.10', 'veriage.test'))->get('/fr/login');
        self::assertSame(301, $apex->status());
        self::assertSame('https://www.veriage.test/fr/login', $apex->header('Location'));
        // Erreurs de l'API en JSON, avec les en-têtes de sécurité.
        $notFound = HttpClient::verify()->get('/api/v1/nope');
        self::assertSame('not_found', self::body($notFound)['error']['code']);
        self::assertSame('nosniff', $notFound->header('X-Content-Type-Options'));
        $method = HttpClient::verify()->json('PUT', '/api/v1/sessions', [], ['Authorization' => 'Bearer ' . $p['keys']['test']]);
        self::assertSame(405, $method->status());
        self::assertSame('method_not_allowed', self::body($method)['error']['code']);
    }

    public function testHostMapFromConfiguration(): void
    {
        $map = HostMap::fromConfig(TestApplication::boot()->config);
        self::assertSame(['site'], $map->areasFor('www.veriage.test'));
        self::assertSame(['verify'], $map->areasFor('verify.veriage.test'));
        self::assertSame(['demo'], $map->areasFor('demo.veriage.test'));
        self::assertSame([], $map->areasFor(''));
        $production = HostMap::fromConfig(TestApplication::boot(['app.demo_enabled' => false])->config);
        self::assertFalse($production->has('demo'), 'démo absente si désactivée');
        $single = new HostMap(['site' => 'http://127.0.0.1:8000', 'verify' => 'http://127.0.0.1:8000']);
        self::assertSame(['site', 'verify'], $single->areasFor('127.0.0.1:8000'));
    }

    private function serviceWithGate(CreditGateInterface $gate): VerificationService
    {
        $app = TestApplication::boot();
        $app->setCreditGate($gate);

        return VerificationService::fromApplication($app);
    }
}
