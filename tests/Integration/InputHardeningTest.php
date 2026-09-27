<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/**
 * Exigences de l'audit critique : validation des champs texte (encodage, caractères de contrôle),
 * mots de passe courants et contextuels, délai d'inactivité des sessions, déconnexion sans jeton.
 */
final class InputHardeningTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    /** @return array<string, string> */
    private function registration(string $company, string $email = 'dev@acme.test', string $password = self::PASSWORD): array
    {
        return ['company' => $company, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    public function testInvalidCompanyNamesAreRejectedWith422AndNothingIsCreated(): void
    {
        $failuresBefore = substr_count(implode("\n", TestApplication::logLines()), 'deferred_task_failed');
        foreach (["Bad\xFF\xFECo", "\u{202E}evil\x01\x07", "Acme\r\nBcc: victim@example.be", "Ac\u{200B}me"] as $company) {
            $client = new HttpClient();
            $response = $client->submit('/fr/register', '/fr/register', $this->registration($company));
            self::assertSame(422, $response->status(), bin2hex($company));
            self::assertStringContainsString('caractères non autorisés', $response->body());
            self::assertTrue(mb_check_encoding($response->body(), 'UTF-8'), 'la saisie réaffichée reste de l\'UTF-8 valide');
        }
        self::assertNull($this->app->db()->fetchOne('SELECT id FROM users'));
        self::assertSame([], TestApplication::outbox(), 'aucun faux succès, aucun e-mail');
        self::assertSame($failuresBefore, substr_count(implode("\n", TestApplication::logLines()), 'deferred_task_failed'));
    }

    public function testCompanyNameIsStoredNormalized(): void
    {
        $response = (new HttpClient())->submit('/fr/register', '/fr/register', $this->registration("  Cafe\u{0301}   du\u{00A0}Parc  "));
        self::assertSame(302, $response->status());
        self::assertSame("Caf\u{00E9} du Parc", $this->app->db()->fetchOne('SELECT name FROM accounts')['name']);
    }

    public function testInvalidEmailBytesAreRejected(): void
    {
        $response = (new HttpClient())->submit('/en/register', '/en/register', $this->registration('Acme', "dev\xFF@acme.test"));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Enter a valid email address.', $response->body());

        $login = (new HttpClient('198.51.100.60'))->submit('/en/login', '/en/login', ['email' => "dev\xFF@acme.test", 'password' => self::PASSWORD]);
        self::assertSame(422, $login->status(), 'échec générique, pas d\'erreur SQL');
    }

    public function testCommonAndContextualPasswordsAreRejected(): void
    {
        $cases = [
            'password1234' => 'figure parmi les plus utilisés',
            'aaaaaaaaaaaa' => 'trop prévisible',
            '123456789012' => 'trop prévisible',
            'Brasserie Lambic forever' => 'ni le nom de votre entreprise',
            'dev.at.acme-2026!!' => 'ni le nom de votre entreprise',
        ];
        foreach ($cases as $password => $message) {
            $password = (string) $password;
            $response = (new HttpClient())->submit('/fr/register', '/fr/register', $this->registration('Brasserie Lambic', 'dev@acme.test', $password));
            self::assertSame(422, $response->status(), $password);
            self::assertStringContainsString($message, $response->body(), $password);
        }
        self::assertNull($this->app->db()->fetchOne('SELECT id FROM users'));
    }

    public function testResetRefusesContextualPassword(): void
    {
        $client = new HttpClient();
        $client->submit('/en/register', '/en/register', $this->registration('Brasserie Lambic'));
        TestApplication::clearOutbox();
        $client->submit('/en/forgot-password', '/en/forgot-password', ['email' => 'dev@acme.test']);
        preg_match('#/en/reset-password\?token=([A-Za-z0-9_-]{43})#', TestApplication::outbox()[0], $m);

        $response = $client->submit('/en/reset-password?token=' . $m[1], '/en/reset-password', [
            'token' => $m[1], 'password' => 'lambic every evening', 'password_confirmation' => 'lambic every evening',
        ]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('must not contain your email address, your company name', $response->body());
    }

    public function testSessionIdleTimeoutIsThirtyMinutes(): void
    {
        self::assertSame(30, $this->app->config->get('security.session.idle_minutes'));

        $client = new HttpClient();
        $response = $client->get('/fr/login');
        [$pair] = explode(';', $response->cookies()[0]);
        $id = explode('=', $pair, 2)[1];
        $ttl = $this->app->redis()->ttl('sess:' . $id);
        self::assertGreaterThan(1790, $ttl);
        self::assertLessThanOrEqual(1800, $ttl);
    }

    public function testLogoutWithoutTokenIs419(): void
    {
        self::assertSame(419, (new HttpClient())->post('/fr/logout')->status());
    }
}
