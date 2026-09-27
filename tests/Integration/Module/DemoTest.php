<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Verification\Webhooks\WebhookSignature;
use Tests\Support\HttpClient;

/** Démonstration : disponibilité, CSP adaptée au widget, réception des webhooks comme un client. */
final class DemoTest extends ModuleTestCase
{
    private const SECRET = 'whsec_test_demo_signing_secret_0123456789abcdef';

    public function testDemoPageLoadsTheWidgetFromTheModuleHost(): void
    {
        $page = HttpClient::demo()->get('/demo?lang=fr');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('<script src="https://verify.veriage.test/widget/verify.js"></script>', $page->body());
        $csp = (string) $page->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' https://verify.veriage.test;", $csp);
        self::assertStringContainsString('frame-src https://verify.veriage.test', $csp);
        self::assertSame('same-origin-allow-popups', $page->header('Cross-Origin-Opener-Policy'));
        self::assertStringContainsString('camera=(self "https://verify.veriage.test")', (string) $page->header('Permissions-Policy'));
        self::assertStringContainsString('Démonstration non configurée', $page->body(), 'sans clé : instructions');
        self::assertStringContainsString('Parkside Cellar', HttpClient::demo()->get('/demo?lang=en')->body());
    }

    public function testDemoIsUnavailableWhenDisabledOrOnOtherHosts(): void
    {
        self::assertSame(404, HttpClient::demo(['app.demo_enabled' => false])->get('/demo')->status());
        self::assertSame(404, (new HttpClient())->get('/demo')->status());
        self::assertSame(404, HttpClient::verify()->get('/demo')->status());
    }

    public function testDemoJsonEndpointsRequireThePageToken(): void
    {
        self::assertSame(419, HttpClient::demo()->json('POST', '/demo/sessions', ['email' => 'a@b.be'])->status());
        self::assertSame(419, HttpClient::demo()->get('/demo/status?session_id=vs_x')->status());
    }

    public function testDemoWebhookVerifiesSignatureReplayWindowAndDuplicates(): void
    {
        $payload = json_encode(['id' => 'evt_demo1', 'type' => 'verification.completed', 'data' => ['session_id' => 'vs_' . str_repeat('a', 32), 'status' => 'verified', 'is_adult' => true]]);
        $post = static fn (string $signature): \App\Core\Response => HttpClient::demo()->json('POST', '/demo/webhook', $payload, [WebhookSignature::HEADER => $signature]);

        self::assertSame(400, $post(WebhookSignature::sign($payload, 'whsec_wrong', time()))->status(), 'mauvais secret');
        self::assertSame(400, $post(WebhookSignature::sign($payload, self::SECRET, time() - 301))->status(), 'rejeu hors fenêtre');
        self::assertSame(400, $post('t=' . time())->status(), 'signature absente');
        $ok = $post(WebhookSignature::sign($payload, self::SECRET, time()));
        self::assertSame(200, $ok->status());
        self::assertSame(['received' => true], self::body($ok));
        self::assertSame(['received' => true, 'duplicate' => true], self::body($post(WebhookSignature::sign($payload, self::SECRET, time()))), 'dédupliqué');
    }
}
