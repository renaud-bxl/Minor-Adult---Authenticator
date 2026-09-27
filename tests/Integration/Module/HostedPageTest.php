<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Verification\ReturnToken;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/** Page de vérification hébergée /s/{session} : parcours, code e-mail, langues, intégration, retour. */
final class HostedPageTest extends ModuleTestCase
{
    public function testPageIsEmbeddableOnlyByTheProjectDomainsAndSetsNoCookie(): void
    {
        $p = $this->createProject(['https://shop.example', 'https://*.shop.example']);
        $session = $this->newSession($p['keys']['test']);
        $page = HttpClient::verify()->get('/s/' . $session['session_id']);
        self::assertSame(200, $page->status());
        $csp = (string) $page->header('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'self' https://shop.example https://*.shop.example;", $csp);
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertNull($page->header('X-Frame-Options'));
        self::assertSame('unsafe-none', $page->header('Cross-Origin-Opener-Policy'), 'popup : window.opener préservé');
        self::assertStringContainsString('camera=(self)', (string) $page->header('Permissions-Policy'));
        self::assertSame([], $page->cookies(), 'aucun cookie (tiers) : état dans l\'URL');
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page->body());

        // Les redirections du parcours gardent ces en-têtes (sinon le popup perdrait window.opener).
        $redirect = HttpClient::verify()->post('/s/' . $session['session_id'] . '/consent', ['_state' => self::state($page)]);
        self::assertSame(303, $redirect->status());
        self::assertSame('unsafe-none', $redirect->header('Cross-Origin-Opener-Policy'));

        self::assertSame(404, HttpClient::verify()->get('/s/vs_' . str_repeat('x', 32))->status());
        self::assertSame(404, HttpClient::verify()->get('/s/vs_short')->status());
    }

    public function testFormsRequireTheSignedStateToken(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test']);
        $client = HttpClient::verify();
        self::assertSame(419, $client->post('/s/' . $session['session_id'] . '/consent', ['consent' => '1'])->status());
        $other = $this->newSession($p['keys']['test'], 'other@example.be');
        $foreignState = self::state($client->get('/s/' . $other['session_id']));
        self::assertSame(419, $client->post('/s/' . $session['session_id'] . '/consent', ['consent' => '1', '_state' => $foreignState])->status(), 'jeton lié à la session');
        $expired = (time() - 10) . '.' . str_repeat('A', 43);
        self::assertSame(419, $client->post('/s/' . $session['session_id'] . '/consent', ['consent' => '1', '_state' => $expired])->status());
    }

    public function testConsentIsRequiredBeforeAnyCode(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test']);
        $client = HttpClient::verify();
        $page = $client->get('/s/' . $session['session_id']);
        $response = $client->post('/s/' . $session['session_id'] . '/consent', ['_state' => self::state($page)]);
        self::assertStringContainsString('notice=consent_required', (string) $response->header('Location'));
        self::assertNull($this->app->db()->fetchOne('SELECT consent_at FROM verification_sessions WHERE public_id = ?', [$session['session_id']])['consent_at']);
        // Sans consentement ni code, la méthode est refusée silencieusement (aucun résultat lié).
        $client->post('/s/' . $session['session_id'] . '/method/mock', ['_state' => self::state($page), 'outcome' => 'adult']);
        self::assertSame('pending', $this->app->db()->fetchOne('SELECT status FROM verification_sessions WHERE public_id = ?', [$session['session_id']])['status']);
    }

    public function testLiveModeSendsTheCodeByEmailAndNeverShowsIt(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['live'], 'real.person@example.be', ['lang' => 'fr']);
        $client = HttpClient::verify();
        $page = $client->get('/s/' . $session['session_id']);
        self::assertStringNotContainsString('sandbox-banner', $page->body());
        $client->post('/s/' . $session['session_id'] . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get('/s/' . $session['session_id']);
        self::assertStringNotContainsString('data-sandbox-code', $page->body());
        self::assertStringContainsString('r•••@e•••.be', $page->body(), 'adresse masquée');

        $mails = TestApplication::outbox();
        self::assertCount(1, $mails);
        self::assertStringContainsString('To: real.person@example.be', $mails[0]);
        self::assertStringContainsString('<title>Votre code de vérification</title>', $mails[0]);
        self::assertStringContainsString('Shop vous demande de vérifier votre âge', $mails[0]);
        $stored = $this->app->db()->fetchOne('SELECT code_hash FROM verification_sessions WHERE public_id = ?', [$session['session_id']]);
        self::assertStringNotContainsString(self::code($page), bin2hex((string) $stored['code_hash']), 'code stocké haché');

        $client->post('/s/' . $session['session_id'] . '/code', ['_state' => self::state($page), 'code' => self::code($page)]);
        $method = $client->get('/s/' . $session['session_id'] . '?shared=declined');
        self::assertStringNotContainsString('/method/mock', $method->body(), 'la simulation n\'existe pas en production');
        self::assertStringContainsString('Aucune méthode de vérification', $method->body());
    }

    public function testWrongCodesFailTheSessionAfterMaxAttempts(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $session = $this->newSession($p['keys']['test'], 'typo@example.be');
        $client = HttpClient::verify();
        $base = '/s/' . $session['session_id'];
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get($base);
        $good = self::code($page);
        $wrong = $good === '000000' ? '111111' : '000000';
        $max = (int) $this->app->config->get('verification.email_code.max_attempts');
        for ($i = 1; $i < $max; $i++) {
            self::assertStringContainsString('notice=code_invalid', (string) $client->post($base . '/code', ['_state' => self::state($page), 'code' => $wrong])->header('Location'));
        }
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => $wrong]);
        // Épuisé : même le bon code est refusé, la session échoue, un webhook « failed » est en file.
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => $good]);
        $row = $this->app->db()->fetchOne('SELECT status, failure_reason, email_verified_at FROM verification_sessions WHERE public_id = ?', [$session['session_id']]);
        self::assertSame(['status' => 'failed', 'failure_reason' => 'code_attempts_exceeded', 'email_verified_at' => null], $row);
        self::assertSame('verification.failed', $this->app->db()->fetchOne('SELECT event_type FROM webhook_deliveries')['event_type']);
        self::assertStringContainsString('data-result-status="failed"', $client->get($base)->body());
    }

    public function testCodeResendIsLimited(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test']);
        $client = HttpClient::verify();
        $base = '/s/' . $session['session_id'];
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        self::assertStringContainsString('notice=code_resend_wait', (string) $client->post($base . '/code/resend', ['_state' => self::state($page)])->header('Location'));

        $this->app->db()->execute('UPDATE verification_sessions SET code_sent_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE public_id = ?', [$session['session_id']]);
        $first = self::code($client->get($base));
        self::assertStringContainsString('notice=code_generated', (string) $client->post($base . '/code/resend', ['_state' => self::state($page)])->header('Location'));
        $second = self::code($client->get($base));
        $this->app->db()->execute('UPDATE verification_sessions SET code_sent_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE public_id = ?', [$session['session_id']]);
        $client->post($base . '/code/resend', ['_state' => self::state($page)]);
        $this->app->db()->execute('UPDATE verification_sessions SET code_sent_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE public_id = ?', [$session['session_id']]);
        self::assertStringContainsString('notice=code_send_limit', (string) $client->post($base . '/code/resend', ['_state' => self::state($page)])->header('Location'));

        // Seul le dernier code est valable ; un code expiré est refusé.
        $third = self::code($client->get($base));
        if ($first !== $third) {
            self::assertStringContainsString('notice=code_invalid', (string) $client->post($base . '/code', ['_state' => self::state($page), 'code' => $first])->header('Location'));
        }
        $this->app->db()->execute('UPDATE verification_sessions SET code_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE public_id = ?', [$session['session_id']]);
        self::assertStringContainsString('notice=code_expired', (string) $client->post($base . '/code', ['_state' => self::state($page), 'code' => $third])->header('Location'));
        self::assertNotSame('', $second);
    }

    public function testPageIsTranslated(): void
    {
        $p = $this->createProject();
        $fr = $this->newSession($p['keys']['test'], 'a@example.be', ['lang' => 'fr']);
        $noLang = $this->newSession($p['keys']['test'], 'b@example.be');
        $client = HttpClient::verify();

        $page = $client->get('/s/' . $fr['session_id']);
        self::assertStringContainsString('<html lang="fr">', $page->body());
        self::assertStringContainsString('Vérifiez votre âge', $page->body());
        self::assertStringContainsString('article 9 du RGPD', $page->body());

        $en = $client->get('/s/' . $fr['session_id'] . '?lang=en');
        self::assertStringContainsString('<html lang="en">', $en->body());
        self::assertStringContainsString('Verify your age', $en->body());
        self::assertStringContainsString('Article 9 of the GDPR', $en->body());
        self::assertStringContainsString('action="/s/' . $fr['session_id'] . '/consent?lang=en"', $en->body(), 'la langue suit le parcours');

        self::assertStringContainsString('<html lang="fr">', $client->get('/s/' . $noLang['session_id'], ['Accept-Language' => 'fr-BE,fr;q=0.9'])->body());
        self::assertStringContainsString('<html lang="en">', $client->get('/s/' . $noLang['session_id'], ['Accept-Language' => 'de-DE'])->body());
        self::assertStringContainsString('<html lang="en">', $client->get('/s/' . $noLang['session_id'] . '?lang=xx')->body());

        // Erreur sur l'hôte du module : page traduite, sans la navigation du site.
        $missing = $client->get('/s/vs_' . str_repeat('Q', 32), ['Accept-Language' => 'fr']);
        self::assertStringContainsString('Page introuvable', $missing->body());
        self::assertStringNotContainsString('/fr/login', $missing->body());
    }

    public function testEmbeddedResultIsPostedOnlyToAnAllowedParentOrigin(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test'], 'embed@example.be', ['return_url' => 'https://shop.example/back']);
        $allowed = $this->completeFlow($session['session_id'], 'adult', false, '?embed=modal&origin=' . rawurlencode(self::ORIGIN));
        self::assertStringContainsString('data-embed="modal"', $allowed->body());
        self::assertStringContainsString('data-parent-origin="https://shop.example"', $allowed->body());
        self::assertMatchesRegularExpression('/id="veriage-result" hidden data-message="[^"]+"/', $allowed->body());
        self::assertStringNotContainsString('data-veriage-return', $allowed->body(), 'intégrée : pas de navigation dans le cadre');
        preg_match('/data-message="([^"]+)"/', $allowed->body(), $m);
        $message = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        self::assertSame('completed', $message['type']);
        self::assertTrue($message['is_adult']);
        self::assertNotNull(ReturnToken::verify($message['token'], $p['secrets']['test'], $p['project']->publicId, $session['session_id']));

        $foreign = HttpClient::verify()->get('/s/' . $session['session_id'] . '?embed=modal&origin=' . rawurlencode('https://evil.example'));
        self::assertStringNotContainsString('data-parent-origin', $foreign->body());
        self::assertStringNotContainsString('veriage-result', $foreign->body(), 'aucun résultat pour une origine non autorisée');
    }

    public function testReturnRedirectCarriesAShortSignedToken(): void
    {
        $p = $this->createProject();
        $session = $this->newSession($p['keys']['test'], 'back@example.be', ['return_url' => 'https://shop.example/back?cart=7', 'external_ref' => 'u_1']);
        $client = HttpClient::verify();
        self::assertSame(303, $client->get('/s/' . $session['session_id'] . '/return')->status(), 'pas de retour avant la fin');
        self::assertStringEndsWith('/s/' . $session['session_id'], (string) $client->get('/s/' . $session['session_id'] . '/return')->header('Location'));

        $result = $this->completeFlow($session['session_id']);
        self::assertStringContainsString('href="/s/' . $session['session_id'] . '/return?lang=', $result->body());
        $redirect = $client->get('/s/' . $session['session_id'] . '/return');
        self::assertSame(303, $redirect->status());
        $location = (string) $redirect->header('Location');
        self::assertStringStartsWith('https://shop.example/back?cart=7&session_id=' . $session['session_id'] . '&token=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $claims = ReturnToken::verify((string) $query['token'], $p['secrets']['test'], $p['project']->publicId, $session['session_id']);
        self::assertNotNull($claims);
        self::assertSame('verified', $claims['status']);
        self::assertTrue($claims['is_adult']);
        self::assertSame('u_1', $claims['external_ref']);
        self::assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('email', $claims, 'aucune adresse dans le jeton (URL)');
        self::assertNull(ReturnToken::verify((string) $query['token'], $p['secrets']['live'], $p['project']->publicId, $session['session_id']), 'secret de l\'autre mode');
        self::assertNull(ReturnToken::verify((string) $query['token'], $p['secrets']['test'], 'prj_other', $session['session_id']), 'autre destinataire');
    }

    public function testCrossClientReuseRequiresBothConsents(): void
    {
        $first = $this->createProject(name: 'First');
        $sharing = $this->createProject(['https://other.example'], acceptShared: true, name: 'Second');
        $closed = $this->createProject(['https://third.example'], name: 'Third');

        // Vérifiée chez le premier client SANS autoriser la réutilisation : rien n'est proposé ailleurs.
        $s1 = $this->newSession($first['keys']['test'], 'nomad@example.be');
        $this->completeFlow($s1['session_id'], 'adult', false);
        $s2 = $this->newSession($sharing['keys']['test'], 'nomad@example.be');
        self::assertStringNotContainsString('/shared', $this->flowUntilAfterCode($s2['session_id'])->body());

        // Nouvelle vérification AVEC autorisation.
        $s3 = $this->newSession($first['keys']['test'], 'traveller@example.be');
        $this->completeFlow($s3['session_id'], 'adult', true);
        $shared = $this->app->db()->fetchOne('SELECT shared_hash FROM verifications WHERE shared_hash IS NOT NULL');
        self::assertNotNull($shared);

        // Un client qui n'admet pas la réutilisation ne la propose pas.
        $s4 = $this->newSession($closed['keys']['test'], 'traveller@example.be');
        self::assertStringNotContainsString('/shared', $this->flowUntilAfterCode($s4['session_id'])->body());

        // Un client qui l'admet la propose APRÈS contrôle de l'adresse ; refus → méthodes.
        $s5 = $this->newSession($sharing['keys']['test'], 'traveller@example.be');
        self::assertSame('pending', $s5['status'], 'jamais de réutilisation automatique entre clients');
        $page = $this->flowUntilAfterCode($s5['session_id']);
        self::assertStringContainsString('Reuse your verification?', $page->body());
        $client = HttpClient::verify();
        $declined = $client->post('/s/' . $s5['session_id'] . '/shared', ['_state' => self::state($page), 'choice' => 'decline']);
        self::assertStringContainsString('shared=declined', (string) $declined->header('Location'));
        $client->post('/s/' . $s5['session_id'] . '/shared', ['_state' => self::state($page), 'choice' => 'accept']);
        $result = $client->get('/s/' . $s5['session_id']);
        self::assertStringContainsString('data-result-status="verified"', $result->body());
        self::assertStringContainsString('Result of a previous verification, reused.', $result->body());
        $status = self::body($this->api($sharing['keys']['test'], 'GET', '/api/v1/verifications?email=traveller@example.be'));
        self::assertSame('verified', $status['status']);
        self::assertSame('shared', $this->app->db()->fetchOne('SELECT reuse FROM verification_sessions WHERE public_id = ?', [$s5['session_id']])['reuse']);
        // La copie n'est pas elle-même une source de réutilisation.
        self::assertSame(1, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM verifications WHERE shared_hash IS NOT NULL')['n']);
        // Jamais entre sandbox et production.
        $live = $this->newSession($sharing['keys']['live'], 'traveller@example.be');
        self::assertSame('pending', $live['status']);
    }

    private function flowUntilAfterCode(string $sessionId): \App\Core\Response
    {
        $client = HttpClient::verify();
        $base = '/s/' . $sessionId;
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get($base);
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => self::code($page)]);

        return $client->get($base);
    }
}
