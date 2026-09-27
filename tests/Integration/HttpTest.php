<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Kernel;
use App\Core\Request;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/** Comportements HTTP transverses : en-têtes, langues, CSRF, erreurs, API i18n. */
final class HttpTest extends IntegrationTestCase
{
    public function testSecurityHeadersOnPagesAndErrors(): void
    {
        $client = new HttpClient();
        foreach (['/fr/' => 200, '/fr/does-not-exist' => 404, '/api/v1/i18n/xx' => 404] as $uri => $status) {
            $response = $client->get($uri);
            self::assertSame($status, $response->status(), $uri);
            $csp = (string) $response->header('Content-Security-Policy');
            self::assertStringContainsString("default-src 'none'", $csp);
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringNotContainsString('unsafe-inline', $csp);
            self::assertStringContainsString("frame-ancestors 'none'", $csp);
            self::assertStringStartsWith('max-age=31536000', (string) $response->header('Strict-Transport-Security'));
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertSame('no-referrer', $response->header('Referrer-Policy'));
        }
    }

    public function testRootRedirectsAccordingToAcceptLanguage(): void
    {
        $response = (new HttpClient())->get('/', ['Accept-Language' => 'de-DE, fr-BE;q=0.8, en;q=0.5']);
        self::assertSame(302, $response->status());
        self::assertSame('/fr/', $response->header('Location'));
        self::assertStringContainsString('Accept-Language', (string) $response->header('Vary'));

        self::assertSame('/en/', (new HttpClient())->get('/', ['Accept-Language' => 'ja'])->header('Location'));
        self::assertSame('/en/', (new HttpClient())->get('/')->header('Location'));
    }

    public function testAnonymousHomeDoesNotCreateASession(): void
    {
        self::assertSame([], (new HttpClient())->get('/fr/')->cookies());
    }

    public function testPagesAreLocalizedWithHreflang(): void
    {
        $body = (new HttpClient())->get('/en/login')->body();
        self::assertStringContainsString('<html lang="en">', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="fr" href="https://www.veriage.test/fr/login">', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://www.veriage.test/en/login">', $body);
        self::assertStringContainsString('href="/fr/login?lang=fr"', $body, 'sélecteur de langue');
        self::assertStringNotContainsString('<script>', $body, 'aucun script inline');
    }

    public function testDisabledOrUnknownLanguageIs404(): void
    {
        $client = new HttpClient();
        self::assertSame(404, $client->get('/de/')->status());
        self::assertSame(404, $client->get('/xx/login')->status());
    }

    public function testLanguageChoiceIsRememberedAndOverridesUrlPrefix(): void
    {
        $client = new HttpClient();
        $response = $client->get('/en/login?lang=fr');
        self::assertSame('/fr/login', $response->header('Location'));
        self::assertCount(1, $response->cookies(), 'choix mémorisé en session');

        // Un lien vers une autre langue renvoie vers la langue choisie.
        self::assertSame('/fr/register', $client->get('/en/register')->header('Location'));
        self::assertSame(200, $client->get('/fr/register')->status());
    }

    public function testPostWithoutCsrfTokenIs419(): void
    {
        $client = new HttpClient();
        $client->get('/fr/login');
        $response = $client->post('/fr/login', ['email' => 'a@b.be', 'password' => 'whatever-long']);
        self::assertSame(419, $response->status());
        self::assertStringContainsString('Session expirée', $response->body());

        $response = $client->post('/fr/login', ['_token' => str_repeat('0', 64), 'email' => 'a@b.be', 'password' => 'x']);
        self::assertSame(419, $response->status());
        self::assertSame(419, (new HttpClient())->post('/en/register', [])->status(), 'sans session');
    }

    public function testMethodNotAllowed(): void
    {
        $response = (new HttpClient())->request('DELETE', '/fr/login');
        self::assertSame(405, $response->status());
        self::assertSame('GET, POST, HEAD', $response->header('Allow'));
    }

    public function testDashboardRequiresAuthentication(): void
    {
        $client = new HttpClient();
        $response = $client->get('/en/dashboard');
        self::assertSame('/en/login', $response->header('Location'));
        self::assertStringContainsString('Please sign in to access this page.', $client->get('/en/login')->body());
    }

    public function testI18nApi(): void
    {
        $client = new HttpClient();
        $response = $client->get('/api/v1/i18n/fr');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('public, max-age=3600', $response->header('Cache-Control'));
        self::assertSame('*', $response->header('Access-Control-Allow-Origin'));
        self::assertSame([], $response->cookies());
        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fr', $data['locale']);
        self::assertSame('Vérifier mon âge', $data['messages']['widget.open']);

        $etag = (string) $response->header('ETag');
        $cached = $client->get('/api/v1/i18n/fr', ['If-None-Match' => $etag]);
        self::assertSame(304, $cached->status());
        self::assertSame('', $cached->body());

        $missing = $client->get('/api/v1/i18n/de');
        self::assertSame(404, $missing->status());
        self::assertSame('not_found', json_decode($missing->body(), true)['error']['code']);
    }

    public function testUnexpectedErrorsAreGeneric500(): void
    {
        // Redis injoignable : erreur 500 générique, traduite, sans détail technique.
        $broken = Application::boot($this->app->basePath, [
            'redis.port' => 1,
            'app.log_path' => TestApplication::directory() . '/logs',
        ]);
        $request = new Request('GET', '/fr/login', [], [], ['accept-language' => 'fr'], [], ['REMOTE_ADDR' => '203.0.113.1']);
        $response = (new Kernel($broken))->handle($request);
        self::assertSame(500, $response->status());
        self::assertStringContainsString('Erreur interne', $response->body());
        self::assertStringNotContainsString('Predis', $response->body());
        self::assertSame("default-src 'none'", explode(';', (string) $response->header('Content-Security-Policy'))[0]);
    }
}
