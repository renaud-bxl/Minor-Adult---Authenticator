<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/**
 * Parcours complet sur MariaDB + Redis réels : inscription → validation → connexion →
 * mot de passe oublié → réinitialisation (avec invalidation des sessions existantes).
 */
final class AuthFlowTest extends IntegrationTestCase
{
    private const EMAIL = 'Owner@Example.be';
    private const PASSWORD = 'correct horse battery staple';

    public function testFullRegistrationLoginAndResetFlow(): void
    {
        $client = new HttpClient();

        // 1. Inscription.
        $response = $client->submit('/fr/register', '/fr/register', [
            'company' => 'Brasserie Test SRL',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
        self::assertSame(302, $response->status());
        self::assertSame('/fr/login', $response->header('Location'));
        self::assertStringContainsString('confirmer votre inscription', $client->get('/fr/login')->body());

        $user = $this->app->db()->fetchOne('SELECT * FROM users');
        self::assertSame('owner@example.be', $user['email'], 'e-mail normalisé');
        self::assertStringStartsWith('$argon2id$', $user['password_hash']);
        self::assertNull($user['email_verified_at']);
        $account = $this->app->db()->fetchOne('SELECT a.name, a.locale, au.role FROM accounts a JOIN account_users au ON au.account_id = a.id');
        self::assertSame(['name' => 'Brasserie Test SRL', 'locale' => 'fr', 'role' => 'owner'], $account);
        $token = $this->app->db()->fetchOne('SELECT token_hash, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires_at) AS ttl FROM user_tokens');
        self::assertSame(32, strlen($token['token_hash']), 'jeton stocké hashé');
        self::assertGreaterThanOrEqual(1438, (int) $token['ttl']);

        // 2. Connexion refusée tant que l'adresse n'est pas confirmée (et lien renvoyé).
        $response = $client->submit('/fr/login', '/fr/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(403, $response->status());
        self::assertStringContainsString('pas encore confirmée', $response->body());
        $mails = TestApplication::outbox();
        self::assertCount(2, $mails);
        self::assertStringContainsString('Subject: Confirmez votre adresse e-mail', $mails[0]);
        $verifyUrl = $this->extractUrl(end($mails), '/fr/verify-email');

        // Le premier lien a été révoqué par le renvoi : seul le dernier est valide.
        $firstUrl = $this->extractUrl($mails[0], '/fr/verify-email');
        self::assertSame(400, $client->get($firstUrl)->status());

        // 3. Validation de l'adresse (usage unique).
        $response = $client->get($verifyUrl);
        self::assertSame(302, $response->status());
        self::assertSame('/fr/login', $response->header('Location'));
        self::assertNotNull($this->app->db()->fetchOne('SELECT email_verified_at FROM users')['email_verified_at']);
        // Lien suivi une seconde fois (ex. scanner de liens de la messagerie) : succès idempotent.
        $again = $client->get($verifyUrl);
        self::assertSame(302, $again->status(), 'jeton déjà consommé, adresse vérifiée');
        self::assertStringContainsString('adresse e-mail est confirmée', $client->get('/fr/login')->body());

        // 4. Connexion : nouvel identifiant de session, tableau de bord accessible.
        $client->get('/fr/login');
        $response = $client->submit('/fr/login', '/fr/login', ['email' => 'owner@example.be', 'password' => self::PASSWORD]);
        self::assertSame(302, $response->status());
        self::assertSame('/fr/dashboard', $response->header('Location'));
        self::assertCount(1, $response->cookies(), 'identifiant régénéré à la connexion');
        $dashboard = $client->get('/fr/dashboard');
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Bienvenue, Brasserie Test SRL', $dashboard->body());
        self::assertStringContainsString('Propriétaire', $dashboard->body());
        self::assertSame(302, $client->get('/fr/login')->status(), 'invité uniquement');

        // 5. Mot de passe oublié depuis un autre navigateur.
        $other = new HttpClient('198.51.100.20');
        TestApplication::clearOutbox();
        $response = $other->submit('/en/forgot-password', '/en/forgot-password', ['email' => self::EMAIL]);
        self::assertSame(302, $response->status());
        $mails = TestApplication::outbox();
        self::assertCount(1, $mails);
        self::assertStringContainsString('Subject: Reset your password', $mails[0]);
        $resetUrl = $this->extractUrl($mails[0], '/en/reset-password');

        self::assertSame(200, $other->get($resetUrl)->status());
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        // Validation : mot de passe trop court refusé, jeton non consommé.
        $response = $other->submit($resetUrl, '/en/reset-password', ['token' => $query['token'], 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('at least 12 characters', $response->body());

        $newPassword = 'a brand new passphrase 2026';
        $response = $other->submit($resetUrl, '/en/reset-password', [
            'token' => $query['token'],
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);
        self::assertSame(302, $response->status());
        self::assertSame('/en/login', $response->header('Location'));
        self::assertSame(400, $other->get($resetUrl)->status(), 'lien à usage unique');
        self::assertStringContainsString('Subject: Your password has been changed', TestApplication::outbox()[1]);

        // 6. La session ouverte avant la réinitialisation est invalidée.
        $response = $client->get('/fr/dashboard');
        self::assertSame(302, $response->status());
        self::assertSame('/fr/login', $response->header('Location'));

        // 7. Ancien mot de passe refusé, nouveau accepté.
        $fresh = new HttpClient('198.51.100.30');
        self::assertSame(422, $fresh->submit('/fr/login', '/fr/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->status());
        self::assertSame(302, $fresh->submit('/fr/login', '/fr/login', ['email' => self::EMAIL, 'password' => $newPassword])->status());

        // Changement de langue connecté : mémorisé comme préférence du compte.
        self::assertSame('/en/dashboard', $fresh->get('/fr/dashboard?lang=en')->header('Location'));
        self::assertSame('en', $this->app->db()->fetchOne('SELECT locale FROM accounts')['locale']);
        self::assertStringContainsString('Welcome, Brasserie Test SRL', $fresh->get('/en/dashboard')->body());

        // 8. Déconnexion.
        $response = $fresh->submit('/en/dashboard', '/en/logout', []);
        self::assertSame(302, $response->status());
        self::assertSame(302, $fresh->get('/en/dashboard')->status());

        // Journal d'audit : actions tracées, IP tronquée, aucune donnée d'identité.
        $audit = $this->app->db()->fetchAll('SELECT action, ip_truncated FROM audit_log ORDER BY id');
        $actions = array_column($audit, 'action');
        foreach (['auth.registered', 'auth.email_verified', 'auth.login', 'auth.password_reset_requested', 'auth.password_reset', 'auth.login_failed', 'auth.logout'] as $action) {
            self::assertContains($action, $actions);
        }
        self::assertSame(['203.0.113.0', '198.51.100.0'], array_values(array_unique(array_column($audit, 'ip_truncated'))));

        // RGPD : aucune adresse e-mail ni IP complète dans les journaux applicatifs.
        $logs = implode("\n", TestApplication::logLines());
        self::assertStringNotContainsStringIgnoringCase('owner@example.be', $logs);
        self::assertStringNotContainsString('203.0.113.10', $logs);
    }

    public function testRegistrationValidationErrors(): void
    {
        $client = new HttpClient();
        $response = $client->submit('/en/register', '/en/register', [
            'company' => 'A',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        self::assertSame(422, $response->status());
        $body = $response->body();
        self::assertStringContainsString('Enter your company name.', $body);
        self::assertStringContainsString('Enter a valid email address.', $body);
        self::assertStringContainsString('at least 12 characters', $body);
        self::assertStringContainsString('value="not-an-email"', $body, 'saisie conservée');

        $response = $client->submit('/en/register', '/en/register', [
            'company' => 'Acme',
            'email' => 'dev@acme.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD . 'x',
        ]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('The two passwords do not match.', $response->body());
        self::assertNull($this->app->db()->fetchOne('SELECT id FROM users'));
    }

    public function testNoAccountEnumeration(): void
    {
        $client = new HttpClient();
        $data = ['company' => 'Acme', 'email' => 'dev@acme.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $first = $client->submit('/en/register', '/en/register', $data);
        $second = (new HttpClient('198.51.100.40'))->submit('/en/register', '/en/register', [...$data, 'company' => 'Other']);

        // Même réponse pour une adresse nouvelle ou déjà inscrite ; le titulaire est prévenu par e-mail.
        self::assertSame($first->status(), $second->status());
        self::assertSame($first->header('Location'), $second->header('Location'));
        self::assertSame(1, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM users')['n']);
        $mails = TestApplication::outbox();
        self::assertCount(2, $mails);
        self::assertStringContainsString('Subject: Sign-up attempt with your address', $mails[1]);

        // Connexion : même message pour une adresse inconnue et un mauvais mot de passe.
        $unknown = (new HttpClient('198.51.100.41'))->submit('/en/login', '/en/login', ['email' => 'nobody@acme.test', 'password' => self::PASSWORD]);
        $wrong = (new HttpClient('198.51.100.42'))->submit('/en/login', '/en/login', ['email' => 'dev@acme.test', 'password' => 'wrong password here']);
        self::assertSame(422, $unknown->status());
        self::assertSame(422, $wrong->status());
        self::assertStringContainsString('Incorrect email address or password.', $unknown->body());
        self::assertStringContainsString('Incorrect email address or password.', $wrong->body());

        // Mot de passe oublié : même redirection et même message, e-mail uniquement si le compte existe.
        TestApplication::clearOutbox();
        $known = (new HttpClient('198.51.100.43'))->submit('/en/forgot-password', '/en/forgot-password', ['email' => 'dev@acme.test']);
        $none = (new HttpClient('198.51.100.44'))->submit('/en/forgot-password', '/en/forgot-password', ['email' => 'nobody@acme.test']);
        self::assertSame([$known->status(), $known->header('Location')], [$none->status(), $none->header('Location')]);
        self::assertCount(1, TestApplication::outbox());
    }

    public function testSixthLoginAttemptIsThrottled(): void
    {
        $client = new HttpClient();
        for ($i = 1; $i <= 5; $i++) {
            $response = $client->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => 'guess number ' . $i]);
            self::assertSame(422, $response->status(), 'tentative ' . $i);
        }
        $response = $client->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => 'guess number 6']);
        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));
        self::assertStringContainsString('Too many attempts', $response->body());

        // Un tiers ne verrouille pas le compte : depuis une autre IP, le titulaire peut encore se connecter.
        $elsewhere = (new HttpClient('198.51.100.50'))->submit('/en/login', '/en/login', ['email' => 'TARGET@acme.test', 'password' => 'guess 7']);
        self::assertSame(422, $elsewhere->status());
    }

    public function testDistributedGuessingHitsThePerAddressCeiling(): void
    {
        // 4 IP × 5 tentatives = plafond global de 20 par adresse et par heure.
        for ($n = 1; $n <= 4; $n++) {
            $client = new HttpClient('198.51.100.' . $n);
            for ($i = 1; $i <= 5; $i++) {
                self::assertSame(422, $client->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => "guess {$n}-{$i}"])->status());
            }
        }
        $response = (new HttpClient('198.51.100.99'))->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => 'guess 21']);
        self::assertSame(429, $response->status());
        $other = (new HttpClient('198.51.100.99'))->submit('/en/login', '/en/login', ['email' => 'other@acme.test', 'password' => 'guess 22']);
        self::assertSame(422, $other->status(), 'autres adresses non affectées');
    }

    public function testIpv6ClientsAreThrottledPerSlash64(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            // Une adresse différente du même /64 à chaque tentative : même compteur.
            $client = new HttpClient('2001:db8:1:2::' . dechex($i));
            self::assertSame(422, $client->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => 'guess ' . $i])->status());
        }
        $response = (new HttpClient('2001:db8:1:2::ff'))->submit('/en/login', '/en/login', ['email' => 'target@acme.test', 'password' => 'guess 6']);
        self::assertSame(429, $response->status());
    }

    public function testSpoofedForwardedForIsIgnored(): void
    {
        // Client direct (aucun proxy de confiance configuré) qui change d'X-Forwarded-For à chaque essai.
        $client = new HttpClient();
        for ($i = 1; $i <= 5; $i++) {
            $token = $client->csrfToken('/en/login');
            $client->request('POST', '/en/login', ['_token' => $token, 'email' => 'target@acme.test', 'password' => 'guess ' . $i], ['X-Forwarded-For' => '192.0.2.' . $i]);
        }
        $token = $client->csrfToken('/en/login');
        $response = $client->request('POST', '/en/login', ['_token' => $token, 'email' => 'target@acme.test', 'password' => 'guess 6'], ['X-Forwarded-For' => '192.0.2.77']);
        self::assertSame(429, $response->status(), 'X-Forwarded-For d\'un client direct : jamais cru');
    }

    public function testRepeatedSignUpsDoNotFloodTheOwnerMailbox(): void
    {
        $data = ['company' => 'Acme', 'email' => 'dev@acme.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        for ($i = 1; $i <= 5; $i++) {
            $response = (new HttpClient('198.51.100.' . $i))->submit('/en/register', '/en/register', $data);
            self::assertSame(302, $response->status());
            self::assertSame('/en/login', $response->header('Location'));
        }
        // 1 e-mail de validation + 2 avertissements, puis plus rien (quota de 3 par adresse et par heure).
        self::assertCount(3, TestApplication::outbox());
        self::assertSame(1, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM users')['n']);
    }

    public function testForgotPasswordWorkRunsAfterTheResponse(): void
    {
        (new HttpClient())->submit('/en/register', '/en/register', ['company' => 'Acme', 'email' => 'dev@acme.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        TestApplication::clearOutbox();

        // Sans exécuter les traitements reportés : réponse identique, aucun travail fait pendant la requête.
        $client = new HttpClient();
        $token = $client->csrfToken('/en/forgot-password');
        $app = TestApplication::boot();
        $request = new \App\Core\Request('POST', '/en/forgot-password', [], ['_token' => $token, 'email' => 'dev@acme.test'], [], $client->cookies(), ['REMOTE_ADDR' => '203.0.113.10']);
        $response = (new \App\Core\Kernel($app))->handle($request);
        self::assertSame('/en/login', $response->header('Location'));
        self::assertSame([], TestApplication::outbox());
        self::assertNull($this->app->db()->fetchOne("SELECT id FROM user_tokens WHERE type = 'password_reset'"));

        $app->runDeferred();
        self::assertCount(1, TestApplication::outbox());
        self::assertNotNull($this->app->db()->fetchOne("SELECT id FROM user_tokens WHERE type = 'password_reset'"));
    }

    public function testInvalidResetTokenShowsError(): void
    {
        $client = new HttpClient();
        self::assertSame(400, $client->get('/en/reset-password?token=forged')->status());
        self::assertSame(400, $client->get('/en/reset-password')->status());
        $response = $client->submit('/en/login', '/en/reset-password', ['token' => 'forged', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        self::assertSame(400, $response->status());
        self::assertStringContainsString('Invalid reset link', $response->body());
    }

    public function testExpiredResetTokenIsRejected(): void
    {
        $client = new HttpClient();
        $client->submit('/en/register', '/en/register', ['company' => 'Acme', 'email' => 'dev@acme.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        TestApplication::clearOutbox();
        $client->submit('/en/forgot-password', '/en/forgot-password', ['email' => 'dev@acme.test']);
        $resetUrl = $this->extractUrl(TestApplication::outbox()[0], '/en/reset-password');

        $this->app->db()->execute("UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE type = 'password_reset'");
        self::assertSame(400, $client->get($resetUrl)->status());
    }

    private function extractUrl(string $mail, string $path): string
    {
        $pattern = '#https://www\.veriage\.test(' . preg_quote($path, '#') . '\?token=[A-Za-z0-9_-]{43})#';
        self::assertSame(1, preg_match($pattern, $mail, $m), 'lien ' . $path . ' introuvable dans l\'e-mail');

        return $m[1];
    }
}
