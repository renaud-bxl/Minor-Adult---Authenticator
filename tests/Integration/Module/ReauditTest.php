<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Services\ProjectAdmin;
use App\Verification\ReturnToken;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/**
 * Corrections de l'audit critique de la phase 2 : validité courte des résultats négatifs (E1),
 * consultation par âge, fenêtre d'émission du jeton de retour (Q2), preuve renforcée (Q1).
 */
final class ReauditTest extends ModuleTestCase
{
    public function testNegativeResultExpiresQuicklySoThePersonCanBeReverified(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $this->completeFlow($this->newSession($key, 'teen@example.be')['session_id'], 'minor');

        $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=teen@example.be'));
        self::assertSame('verified', $status['status']);
        self::assertFalse($status['is_adult']);
        $ttl = strtotime($status['expires_at']) - time();
        self::assertGreaterThan(23 * 3600, $ttl);
        self::assertLessThanOrEqual(24 * 3600, $ttl, 'validité courte (24 h par défaut), et non 365 jours');

        // Dans la fenêtre : réutilisé (évite une boucle de vérifications) ; au-delà : revérification possible.
        $again = self::body($this->api($key, 'POST', '/api/v1/sessions', ['email' => 'teen@example.be']));
        self::assertSame('verified', $again['status']);
        self::assertFalse($again['verification']['is_adult']);
        self::assertSame($status['expires_at'], $again['verification']['expires_at'], 'même expiration partout');
        $this->app->db()->execute('UPDATE verifications SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
        $fresh = $this->api($key, 'POST', '/api/v1/sessions', ['email' => 'teen@example.be']);
        self::assertSame(201, $fresh->status());
        self::assertSame('pending', self::body($fresh)['status']);
        $this->completeFlow(self::body($fresh)['session_id'], 'adult');
        self::assertTrue(self::body($this->api($key, 'GET', '/api/v1/verifications?email=teen@example.be'))['is_adult'], 'majeur une fois revérifié');
    }

    public function testPositiveResultKeepsTheProjectValidity(): void
    {
        $p = $this->createProject();
        $this->completeFlow($this->newSession($p['keys']['test'], 'adult@example.be')['session_id'], 'adult');
        $status = self::body($this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=adult@example.be'));
        self::assertGreaterThan(strtotime('+364 days'), strtotime($status['expires_at']));
        $this->app->db()->execute('UPDATE verifications SET verified_at = UTC_TIMESTAMP() - INTERVAL 30 DAY');
        self::assertSame(200, $this->api($p['keys']['test'], 'POST', '/api/v1/sessions', ['email' => 'adult@example.be'])->status(), 'toujours réutilisé');
    }

    public function testZeroNegativeTtlNeverReusesANegativeResultAndFailuresAreNeverReused(): void
    {
        $admin = ProjectAdmin::fromApplication($this->app);
        $created = $admin->create($admin->createAccount('Zero'), 'Zero', [self::ORIGIN], negativeTtlHours: 0);
        self::assertSame(0, $created['project']->negativeTtlHours);
        $key = $created['keys']['test'];
        $session = $this->newSession($key, 'minor@example.be');
        $result = $this->completeFlow($session['session_id'], 'minor');
        self::assertStringContainsString('data-result-status="verified"', $result->body(), 'la personne voit son résultat');
        self::assertSame(201, $this->api($key, 'POST', '/api/v1/sessions', ['email' => 'minor@example.be'])->status());

        // Échec technique : jamais mis en cache, une nouvelle session est aussitôt proposée.
        $failed = $this->newSession($key, 'fail@example.be');
        $this->completeFlow($failed['session_id'], 'fail');
        self::assertSame('pending', $this->newSession($key, 'fail@example.be')['status']);

        $this->expectException(\InvalidArgumentException::class);
        $admin->setNegativeTtl($created['project'], 721);
    }

    public function testSharedNegativeCopyAlsoGetsTheShortValidity(): void
    {
        $first = $this->createProject(name: 'First');
        $second = $this->createProject(['https://other.example'], acceptShared: true, name: 'Second', accountId: $first['project']->accountId);
        $this->completeFlow($this->newSession($first['keys']['test'], 'young@example.be')['session_id'], 'minor', true);

        $session = $this->newSession($second['keys']['test'], 'young@example.be');
        $client = HttpClient::verify();
        $base = '/s/' . $session['session_id'];
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get($base);
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => self::code($page)]);
        $page = $client->get($base);
        $client->post($base . '/shared', ['_state' => self::state($page), 'choice' => 'accept']);

        $copy = self::body($this->api($second['keys']['test'], 'GET', '/api/v1/verifications?email=young@example.be'));
        self::assertSame('verified', $copy['status']);
        self::assertFalse($copy['is_adult']);
        self::assertLessThanOrEqual(time() + 24 * 3600, strtotime($copy['expires_at']));
    }

    public function testLookupByAgeAndInTheBody(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $this->completeFlow($this->newSession($key, 'plus+tag@example.be', ['min_age' => 18])['session_id'], 'adult');

        self::assertSame('verified', self::body($this->api($key, 'GET', '/api/v1/verifications?email=' . rawurlencode('plus+tag@example.be') . '&min_age=18'))['status']);
        self::assertSame('verified', self::body($this->api($key, 'GET', '/api/v1/verifications?email=' . rawurlencode('plus+tag@example.be') . '&min_age=16'))['status']);
        self::assertSame('not_verified', self::body($this->api($key, 'GET', '/api/v1/verifications?email=' . rawurlencode('plus+tag@example.be') . '&min_age=21'))['status'], 'majeur à 18 ne dit rien de 21');
        self::assertSame(['min_age' => 'invalid'], self::body($this->api($key, 'GET', '/api/v1/verifications?email=a@b.be&min_age=17'))['error']['details']);
        self::assertSame(['min_age' => 'invalid'], self::body($this->api($key, 'GET', '/api/v1/verifications?email=a@b.be&min_age=abc'))['error']['details']);

        $lookup = $this->api($key, 'POST', '/api/v1/verifications/lookup', ['email' => 'plus+tag@example.be', 'min_age' => 21]);
        self::assertSame(200, $lookup->status());
        self::assertSame('not_verified', self::body($lookup)['status']);
        self::assertTrue(self::body($this->api($key, 'POST', '/api/v1/verifications/lookup', ['email' => 'plus+tag@example.be']))['is_adult']);
        self::assertSame(['emial' => 'unknown_field'], self::body($this->api($key, 'POST', '/api/v1/verifications/lookup', ['emial' => 'x@y.be']))['error']['details']);
        self::assertSame(['email' => 'invalid'], self::body($this->api($key, 'POST', '/api/v1/verifications/lookup', ['email' => ['x']]))['error']['details']);
        self::assertSame(401, HttpClient::verify()->json('POST', '/api/v1/verifications/lookup', ['email' => 'x@y.be'])->status());
    }

    public function testBearerSchemeIsCaseInsensitive(): void
    {
        $p = $this->createProject();
        foreach (['bearer ', 'BEARER ', 'Bearer  '] as $scheme) {
            self::assertSame(200, HttpClient::verify()->json('GET', '/api/v1/verifications?email=a@b.be', null, ['Authorization' => $scheme . $p['keys']['test']])->status(), $scheme);
        }
    }

    public function testReturnTokenIsOnlyIssuedShortlyAfterTheEnd(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test'], 'late@example.be', ['return_url' => 'https://shop.example/back']);
        $embedded = '?embed=modal&origin=' . rawurlencode(self::ORIGIN);
        $result = $this->completeFlow($session['session_id'], 'adult', false, $embedded);
        preg_match('/data-message="([^"]+)"/', $result->body(), $m);
        $message = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        self::assertNotNull(ReturnToken::verify((string) $message['token'], $p['secrets']['test'], $p['project']->publicId, $session['session_id']));
        self::assertSame(18, $message['min_age']);
        self::assertStringStartsWith('https://shop.example/back?', (string) HttpClient::verify()->get('/s/' . $session['session_id'] . '/return')->header('Location'));

        $window = (int) $this->app->config->get('verification.return_token_window');
        self::assertSame(600, $window, '10 minutes par défaut');
        $this->app->db()->execute('UPDATE verification_sessions SET completed_at = UTC_TIMESTAMP() - INTERVAL ? SECOND', [$window + 5]);
        $late = HttpClient::verify()->get('/s/' . $session['session_id'] . $embedded);
        preg_match('/data-message="([^"]+)"/', $late->body(), $m);
        self::assertNull(json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true)['token'], 'résultat affiché, sans jeton');
        self::assertStringContainsString('data-result-status="verified"', $late->body());
        $redirect = HttpClient::verify()->get('/s/' . $session['session_id'] . '/return');
        self::assertStringStartsWith('/s/' . $session['session_id'], (string) $redirect->header('Location'), 'plus de retour avec jeton');
        self::assertStringNotContainsString('data-veriage-return', HttpClient::verify()->get('/s/' . $session['session_id'])->body());
    }

    public function testManyWrongCodesAcrossClientsSwitchToASingleUseLinkWithoutBlocking(): void
    {
        $p = $this->createProject();
        $email = 'target@example.be';
        $shared = $this->app->crypto()->hashEmail($email, 'veriage-shared-proof-v1');
        [$max, $window] = $this->app->rateLimit('verify_code_global');
        self::assertSame(20, $max);

        // Un code erroné en production alimente le compteur global de l'adresse.
        $session = $this->newSession($p['keys']['live'], $email);
        $client = HttpClient::verify();
        $base = '/s/' . $session['session_id'];
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => '000000']);
        self::assertSame(1, $max - $this->app->rateLimiter()->peek('verify_code_global', $shared, $max, $window)->remaining);

        for ($i = 1; $i < $max; $i++) {
            $this->app->rateLimiter()->attempt('verify_code_global', $shared, $max, $window);
        }
        TestApplication::clearOutbox();
        $other = $this->createProject(['https://other.example'], name: 'Other');
        $next = $this->newSession($other['keys']['live'], $email);
        self::assertSame(201, $this->api($other['keys']['live'], 'POST', '/api/v1/sessions', ['email' => $email])->status(), 'aucun blocage');
        $base = '/s/' . $next['session_id'];
        $page = $client->get($base);
        self::assertStringContainsString('notice=link_sent', (string) $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1'])->header('Location'));
        $page = $client->get($base);
        self::assertStringContainsString('Confirmez votre adresse par le lien reçu', $client->get($base . '?lang=fr')->body());
        self::assertStringNotContainsString('id="code"', $page->body(), 'plus de code à 6 chiffres à deviner');

        $mail = TestApplication::outbox()[0];
        self::assertSame(1, preg_match('#/s/' . $next['session_id'] . '/confirm\?token=([A-Za-z0-9]{32})#', $mail, $m));
        self::assertStringNotContainsString($m[1], implode("\n", TestApplication::logLines()));
        // Six chiffres ne valent plus rien ; le lien ouvre une page de confirmation en POST.
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => '123456']);
        $confirm = $client->get($base . '/confirm?token=' . $m[1]);
        self::assertSame(200, $confirm->status());
        self::assertNull($this->app->db()->fetchOne('SELECT email_verified_at FROM verification_sessions WHERE public_id = ?', [$next['session_id']])['email_verified_at'], 'le GET ne consomme rien');
        $client->post($base . '/code', ['_state' => self::state($confirm), 'code' => $m[1]]);
        self::assertNotNull($this->app->db()->fetchOne('SELECT email_verified_at FROM verification_sessions WHERE public_id = ?', [$next['session_id']])['email_verified_at']);

        $alert = $this->app->db()->fetchOne("SELECT metadata FROM audit_log WHERE action = 'verification.proof_escalated'");
        self::assertNotNull($alert, 'alerte dans le journal d\'audit');
        self::assertStringNotContainsString('target', (string) $alert['metadata']);
    }

    public function testSingleUseLinkExpiresIsReplacedOnResendAndCannotBeGuessed(): void
    {
        $p = $this->createProject();
        $email = 'escalated@example.be';
        [$max, $window] = $this->app->rateLimit('verify_code_global');
        $shared = $this->app->crypto()->hashEmail($email, 'veriage-shared-proof-v1');
        for ($i = 0; $i < $max; $i++) {
            $this->app->rateLimiter()->attempt('verify_code_global', $shared, $max, $window);
        }
        $id = $this->newSession($p['keys']['live'], $email)['session_id'];
        $client = HttpClient::verify();
        $base = '/s/' . $id;
        $page = $client->get($base);
        TestApplication::clearOutbox();
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $link = static function (): string {
            $mails = TestApplication::outbox();
            self::assertSame(1, preg_match('#/confirm\?token=([A-Za-z0-9]{32})#', (string) end($mails), $m));

            return $m[1];
        };
        $first = $link();
        $verified = fn (): bool => $this->app->db()->fetchOne('SELECT email_verified_at FROM verification_sessions WHERE public_id = ?', [$id])['email_verified_at'] !== null;
        $state = self::state($client->get($base));

        // Un jeton de même forme, mais inventé, ne vaut rien.
        $client->post($base . '/code', ['_state' => $state, 'code' => str_repeat('A', 32)]);
        self::assertFalse($verified());
        // Expiré (15 min) : refusé.
        $this->app->db()->execute('UPDATE verification_sessions SET code_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE public_id = ?', [$id]);
        $client->post($base . '/code', ['_state' => $state, 'code' => $first]);
        self::assertFalse($verified());
        // Nouvel envoi : nouveau lien, l'ancien ne vaut plus.
        $this->app->db()->execute('UPDATE verification_sessions SET code_sent_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE public_id = ?', [$id]);
        TestApplication::clearOutbox();
        $client->post($base . '/code/resend', ['_state' => $state]);
        $second = $link();
        self::assertNotSame($first, $second);
        $client->post($base . '/code', ['_state' => $state, 'code' => $first]);
        self::assertFalse($verified());
        $client->post($base . '/code', ['_state' => $state, 'code' => $second]);
        self::assertTrue($verified());
        // Usage unique : le jeton est effacé dès qu'il a servi.
        self::assertNull($this->app->db()->fetchOne('SELECT code_hash FROM verification_sessions WHERE public_id = ?', [$id])['code_hash']);
    }

    public function testCodeErrorIsTiedToTheFieldAndStepsAreAnnounced(): void
    {
        $p = $this->createProject();
        $id = $this->newSession($p['keys']['test'], 'a11y@example.be', ['lang' => 'fr'])['session_id'];
        $client = HttpClient::verify();
        $page = $client->get('/s/' . $id);
        self::assertStringContainsString('<title>Consentement · Vérification de l’âge · VeriAge</title>', $page->body());
        $client->post('/s/' . $id . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $location = (string) $client->post('/s/' . $id . '/code', ['_state' => self::state($page), 'code' => '000001'])->header('Location');
        $error = $client->get($location)->body();
        self::assertStringContainsString('aria-describedby="code-error code-hint" aria-invalid="true"', $error);
        self::assertStringContainsString('id="code-error" role="alert"', $error);
        self::assertStringContainsString('(étape terminée)', $error);
        self::assertStringContainsString('(étape en cours)', $error);
        self::assertStringContainsString('<title>E-mail · Vérification de l’âge · VeriAge</title>', $error);
    }

    public function testExhaustedCodesShowADedicatedMessage(): void
    {
        $p = $this->createProject();
        $id = $this->newSession($p['keys']['test'], 'locked@example.be', ['lang' => 'fr'])['session_id'];
        $client = HttpClient::verify();
        $page = $client->get('/s/' . $id);
        $client->post('/s/' . $id . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        for ($i = 0; $i < 5; $i++) {
            $client->post('/s/' . $id . '/code', ['_state' => self::state($page), 'code' => '00000' . $i]);
        }
        self::assertStringContainsString('Trop de codes incorrects', $client->get('/s/' . $id)->body());
    }
}
