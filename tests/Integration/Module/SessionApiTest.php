<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use Tests\Support\HttpClient;

/** POST /api/v1/sessions, GET et DELETE /api/v1/verifications : contrat, validation, statuts. */
final class SessionApiTest extends ModuleTestCase
{
    public function testCreateSessionReturnsTheDocumentedShape(): void
    {
        $p = $this->createProject();
        $response = $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', [
            'email' => '  User@Exemple.BE ',
            'min_age' => 18,
            'return_url' => 'https://shop.example/retour?cart=12',
            'lang' => 'fr',
            'external_ref' => 'user_4521',
        ]);
        self::assertSame(201, $response->status(), $response->body());
        $body = self::body($response);
        self::assertMatchesRegularExpression('/^vs_[A-Za-z0-9]{32}$/', $body['session_id']);
        self::assertSame('https://verify.veriage.test/s/' . $body['session_id'], $body['verify_url']);
        self::assertSame(1800, $body['expires_in']);
        self::assertSame('pending', $body['status']);
        self::assertFalse($body['livemode']);
        self::assertSame($p['project']->publicId, $body['project']);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('no-store, private', $response->header('Cache-Control'));
        self::assertNull($response->header('Access-Control-Allow-Origin'), 'API secrète : aucun CORS');

        // Stockage : e-mail haché (salé par projet) et chiffré, jamais en clair.
        $row = $this->app->db()->fetchOne('SELECT * FROM verification_sessions WHERE public_id = ?', [$body['session_id']]);
        self::assertNotNull($row);
        self::assertStringNotContainsString('exemple', strtolower((string) $row['email_enc']));
        self::assertSame(64, strlen((string) $row['email_hash']));
        self::assertSame('user_4521', $row['external_ref']);
    }

    public function testValidationErrorsAreNormalized(): void
    {
        $p = $this->createProject();
        $response = $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', [
            'email' => 'not-an-email',
            'min_age' => 17,
            'return_url' => 'http://shop.example/retour',
            'lang' => 'xx',
            'external_ref' => 'someone@example.com',
            'minAge' => 18,
        ]);
        self::assertSame(422, $response->status());
        $error = self::body($response)['error'];
        self::assertSame('validation_failed', $error['code']);
        self::assertSame([
            'email' => 'invalid',
            'external_ref' => 'invalid',
            'lang' => 'unsupported',
            'minAge' => 'unknown_field',
            'min_age' => 'invalid',
            'return_url' => 'insecure_scheme',
        ], $error['details']);
        self::assertNotSame('', $error['message']);

        self::assertSame(['email' => 'required'], self::body($this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['min_age' => 18]))['error']['details']);
    }

    public function testReturnUrlMustBelongToTheProjectDomains(): void
    {
        $p = $this->createProject(['https://shop.example', 'https://*.brand.example']);
        $cases = [
            'https://evil.example/x' => 'origin_not_allowed',
            'https://shop.example.evil.com/x' => 'origin_not_allowed',
            'https://user:pw@shop.example/x' => 'credentials_not_allowed',
            'https://shop.example/x#frag' => 'fragment_not_allowed',
            'https://10.0.0.1/x' => 'private_address',
            'https://[::1]/x' => 'private_address',
            'javascript:alert(1)' => 'invalid_url',
        ];
        foreach ($cases as $url => $code) {
            $response = $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'a@b.be', 'return_url' => $url]);
            self::assertSame(422, $response->status(), $url);
            self::assertSame($code, self::body($response)['error']['details']['return_url'], $url);
        }
        foreach (['https://shop.example/ok', 'https://eu.brand.example/ok', 'https://a.b.brand.example/ok?x=1'] as $url) {
            self::assertSame(201, $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'a@b.be', 'return_url' => $url])->status(), $url);
        }
    }

    public function testBodyMustBeAJsonObject(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $client = HttpClient::verify();
        $auth = ['Authorization' => 'Bearer ' . $key];

        $form = $client->request('POST', '/api/v1/sessions', ['email' => 'a@b.be'], $auth);
        self::assertSame(415, $form->status());
        self::assertSame('unsupported_media_type', self::body($form)['error']['code']);
        self::assertSame('invalid_json', self::body($client->json('POST', '/api/v1/sessions', '{"email":', $auth))['error']['code']);
        self::assertSame(400, $client->json('POST', '/api/v1/sessions', '["a@b.be"]', $auth)->status());
        $big = $client->json('POST', '/api/v1/sessions', json_encode(['email' => str_repeat('a', 70000)]), $auth);
        self::assertSame(413, $big->status());
        self::assertSame('payload_too_large', self::body($big)['error']['code']);
    }

    public function testStatusesNotVerifiedPendingVerifiedFailed(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $status = fn (string $email): array => self::body($this->api($key, 'GET', '/api/v1/verifications?email=' . rawurlencode($email)));

        self::assertSame(['object' => 'verification', 'email' => 'ann@example.be', 'livemode' => false, 'status' => 'not_verified', 'is_adult' => false], $status('Ann@Example.be'));

        $session = $this->newSession($key, 'ann@example.be');
        $pending = $status('ann@example.be');
        self::assertSame('pending', $pending['status']);
        self::assertSame($session['session_id'], $pending['session_id']);

        $this->completeFlow($session['session_id'], 'adult');
        $verified = $status('ann@example.be');
        self::assertSame('verified', $verified['status']);
        self::assertTrue($verified['is_adult']);
        self::assertSame('mock', $verified['method']);
        self::assertSame(18, $verified['min_age']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', $verified['verified_at']);
        self::assertGreaterThan(strtotime('+364 days'), strtotime($verified['expires_at']));

        $failedSession = $this->newSession($key, 'bob@example.be');
        $this->completeFlow($failedSession['session_id'], 'fail');
        $failed = $status('bob@example.be');
        self::assertSame('failed', $failed['status']);
        self::assertSame('mock_failure', $failed['failure_reason']);
        self::assertFalse($failed['is_adult']);

        $minorSession = $this->newSession($key, 'kid@example.be');
        $this->completeFlow($minorSession['session_id'], 'minor');
        $minor = $status('kid@example.be');
        self::assertSame('verified', $minor['status'], 'mineur : vérification aboutie, is_adult false');
        self::assertFalse($minor['is_adult']);
    }

    public function testExpiredSessionIsNotPending(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test'], 'late@example.be');
        $this->app->db()->execute('UPDATE verification_sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE public_id = ?', [$session['session_id']]);
        self::assertSame('not_verified', self::body($this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=late@example.be'))['status']);
        self::assertStringContainsString('data-result-status="expired"', HttpClient::verify()->get('/s/' . $session['session_id'])->body());
    }

    public function testReuseForTheSameClientIsImmediateAndRespectsTheAge(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $first = $this->newSession($key, 'eve@example.be', ['min_age' => 18]);
        $this->completeFlow($first['session_id'], 'adult');
        $sessionsBefore = (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM verifications')['n'];

        $again = $this->api($key, 'POST', '/api/v1/sessions', ['email' => 'EVE@example.be', 'min_age' => 16]);
        self::assertSame(200, $again->status());
        $body = self::body($again);
        self::assertSame('verified', $body['status']);
        self::assertTrue($body['reused']);
        self::assertTrue($body['verification']['is_adult']);
        self::assertSame($sessionsBefore, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM verifications')['n']);
        self::assertStringContainsString('data-result-status="verified"', HttpClient::verify()->get('/s/' . $body['session_id'])->body());

        // Majeur à 18 ans ne prouve rien pour 21 ans : nouvelle vérification exigée.
        $older = self::body($this->api($key, 'POST', '/api/v1/sessions', ['email' => 'eve@example.be', 'min_age' => 21]));
        self::assertSame('pending', $older['status']);
        self::assertArrayNotHasKey('reused', $older);
    }

    public function testEraseDeletesResultAndSessions(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $session = $this->newSession($key, 'gdpr@example.be');
        $this->completeFlow($session['session_id']);

        $response = $this->api($key, 'DELETE', '/api/v1/verifications?email=GDPR@example.be');
        self::assertSame(200, $response->status());
        self::assertSame(['object' => 'verification', 'email' => 'gdpr@example.be', 'deleted' => true, 'livemode' => false], self::body($response));
        self::assertSame('not_verified', self::body($this->api($key, 'GET', '/api/v1/verifications?email=gdpr@example.be'))['status']);
        self::assertNull($this->app->db()->fetchOne('SELECT id FROM verification_sessions WHERE public_id = ?', [$session['session_id']]));
        self::assertFalse(self::body($this->api($key, 'DELETE', '/api/v1/verifications?email=gdpr@example.be'))['deleted'], 'idempotent');
        self::assertSame(['email' => 'required'], self::body($this->api($key, 'DELETE', '/api/v1/verifications'))['error']['details']);
    }

    public function testAuditLogHasNoIdentityData(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test'], 'audit.person@example.be');
        $this->completeFlow($session['session_id']);
        $rows = $this->app->db()->fetchAll("SELECT action, ip_truncated, metadata FROM audit_log WHERE action LIKE 'verification.%' ORDER BY id");
        self::assertSame(
            ['verification.session_created', 'verification.consent', 'verification.email_confirmed', 'verification.completed'],
            array_column($rows, 'action'),
        );
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('audit.person', $dump);
        self::assertStringNotContainsString('203.0.113.10', $dump);
        self::assertStringContainsString('"result":"adult"', implode(' ', array_column($rows, 'metadata')));
        self::assertStringContainsString('203.0.113.0', $dump, 'IP tronquée /24');
        foreach (\Tests\Support\TestApplication::logLines() as $line) {
            self::assertStringNotContainsString('audit.person@example.be', $line);
        }
    }
}
