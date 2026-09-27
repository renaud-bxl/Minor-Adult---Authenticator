<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Models\ProjectRepository;
use App\Verification\UrlGuard;
use App\Verification\Webhooks\WebhookDispatcher;
use App\Verification\Webhooks\WebhookSignature;
use App\Verification\Webhooks\WebhookTransport;
use App\Models\WebhookDeliveryRepository;
use App\Models\WebhookEndpointRepository;

/** Webhooks : contenu signé, livraison, relances exponentielles, idempotence, protection SSRF. */
final class WebhookDeliveryTest extends ModuleTestCase
{
    public function testCompletedSessionIsDeliveredSignedAndOnlyOnce(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks/veriage']);
        $session = $this->newSession($p['keys']['test'], 'hook@example.be', ['external_ref' => 'cust_9']);
        $this->completeFlow($session['session_id']);

        $transport = new RecordingTransport([200]);
        $dispatcher = $this->dispatcher($transport, ['shop.example' => ['93.184.216.34']]);
        self::assertSame(1, $dispatcher->processDue());
        self::assertSame(0, $dispatcher->processDue(), 'livrée : jamais renvoyée');
        self::assertCount(1, $transport->calls);

        $call = $transport->calls[0];
        self::assertSame('https://shop.example/hooks/veriage', $call['url']);
        self::assertSame('93.184.216.34', $call['ip'], 'connexion sur l\'adresse vérifiée');
        self::assertTrue(WebhookSignature::verify($call['body'], $call['headers'][WebhookSignature::HEADER], $p['secrets']['test'], time()));
        self::assertFalse(WebhookSignature::verify($call['body'], $call['headers'][WebhookSignature::HEADER], $p['secrets']['live'], time()));
        $event = json_decode($call['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('verification.completed', $event['type']);
        self::assertSame($event['id'], $call['headers']['X-VeriAge-Event-Id']);
        self::assertFalse($event['livemode']);
        self::assertSame(['session_id', 'external_ref', 'email', 'status', 'is_adult', 'min_age', 'verified_at', 'expires_at', 'method', 'reused', 'failure_reason'], array_keys($event['data']));
        self::assertSame('hook@example.be', $event['data']['email']);
        self::assertSame('cust_9', $event['data']['external_ref']);
        self::assertTrue($event['data']['is_adult']);

        // Le corps stocké en base est chiffré (il contient l'adresse).
        $row = $this->app->db()->fetchOne('SELECT payload_enc, status, attempts, last_status_code FROM webhook_deliveries');
        self::assertStringNotContainsString('hook@example.be', (string) $row['payload_enc']);
        self::assertSame(['delivered', 1, 200], [$row['status'], (int) $row['attempts'], (int) $row['last_status_code']]);
    }

    public function testFailuresAreRetriedWithExponentialBackoffThenAbandoned(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $session = $this->newSession($p['keys']['test']);
        $this->completeFlow($session['session_id']);
        $transport = new RecordingTransport([500, null, 503]);
        $dispatcher = $this->dispatcher($transport, ['shop.example' => ['93.184.216.34']]);

        $dispatcher->processDue();
        $row = $this->delivery();
        self::assertSame(['pending', 1, 500, 'http_500'], [$row['status'], (int) $row['attempts'], (int) $row['last_status_code'], $row['last_error']]);
        $delay = strtotime((string) $row['next_attempt_at'] . ' UTC') - time();
        self::assertGreaterThanOrEqual(25, $delay);
        self::assertLessThanOrEqual(35, $delay);
        self::assertSame(0, $dispatcher->processDue(), 'pas avant l\'échéance');

        $this->makeDue();
        $dispatcher->processDue();
        self::assertSame('timeout', $this->delivery()['last_error']);
        $this->makeDue();
        $dispatcher->processDue();
        self::assertSame(3, (int) $this->delivery()['attempts']);

        // Signature recalculée à chaque tentative (horodatage frais), même identifiant d'événement.
        self::assertSame($transport->calls[0]['headers']['X-VeriAge-Event-Id'], $transport->calls[2]['headers']['X-VeriAge-Event-Id']);
        self::assertSame(['1', '2', '3'], array_map(static fn (array $c): string => $c['headers']['X-VeriAge-Delivery-Attempt'], $transport->calls));

        $max = (int) $this->app->config->get('verification.webhooks.max_attempts');
        $this->app->db()->execute('UPDATE webhook_deliveries SET attempts = ?, next_attempt_at = UTC_TIMESTAMP()', [$max - 1]);
        $this->dispatcher(new RecordingTransport([500]), ['shop.example' => ['93.184.216.34']])->processDue();
        self::assertSame('failed', $this->delivery()['status'], 'abandon après le nombre maximal de tentatives');
    }

    public function testBackoffIsExponentialAndCapped(): void
    {
        foreach ([1 => 30, 2 => 60, 3 => 120, 6 => 960] as $attempt => $expected) {
            $delay = WebhookDispatcher::backoff($attempt, 30, 21600);
            self::assertGreaterThanOrEqual((int) ($expected * 0.9), $delay, (string) $attempt);
            self::assertLessThanOrEqual((int) ($expected * 1.1), $delay, (string) $attempt);
        }
        self::assertLessThanOrEqual(23760, WebhookDispatcher::backoff(40, 30, 21600));
    }

    public function testDnsRebindingToAPrivateAddressIsBlocked(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $session = $this->newSession($p['keys']['test']);
        $this->completeFlow($session['session_id']);
        $transport = new RecordingTransport([200]);
        // Le nom public résout désormais (aussi) vers une adresse interne : abandon, aucune connexion.
        $this->dispatcher($transport, ['shop.example' => ['93.184.216.34', '169.254.169.254']])->processDue();
        self::assertSame([], $transport->calls);
        self::assertSame(['failed', 'private_address'], [$this->delivery()['status'], $this->delivery()['last_error']]);
    }

    public function testUrlNoLongerAllowedIsNotCalled(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $session = $this->newSession($p['keys']['test']);
        $this->completeFlow($session['session_id']);
        (new ProjectRepository($this->app->db(), $this->app->crypto()))->updateOrigins($p['project']->id, ['https://new-shop.example']);
        $transport = new RecordingTransport([200]);
        $this->dispatcher($transport, ['shop.example' => ['93.184.216.34']])->processDue();
        self::assertSame([], $transport->calls);
        self::assertSame('url_rejected_origin_not_allowed', $this->delivery()['last_error']);
    }

    public function testConcurrentWorkersNeverClaimTheSameDelivery(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $this->completeFlow($this->newSession($p['keys']['test'])['session_id']);
        $repository = new WebhookDeliveryRepository($this->app->db());
        self::assertCount(1, $repository->claimDue(10, 60));
        self::assertSame([], $repository->claimDue(10, 60), 'réservée par le premier worker');
        $this->app->db()->execute('UPDATE webhook_deliveries SET locked_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
        self::assertCount(1, $repository->claimDue(10, 60), 'réservation abandonnée : reprise');
    }

    public function testEnqueueIsIdempotentPerEndpointAndEvent(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $endpoint = (new WebhookEndpointRepository($this->app->db()))->enabledFor($p['project']->id, false)[0];
        $repository = new WebhookDeliveryRepository($this->app->db());
        self::assertTrue($repository->enqueue('evt_same', 'verification.completed', (int) $endpoint['id'], $p['project']->id, 'x'));
        self::assertFalse($repository->enqueue('evt_same', 'verification.completed', (int) $endpoint['id'], $p['project']->id, 'x'));
        self::assertSame(1, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries')['n']);
    }

    public function testErasureAlsoDeletesDeliveriesCarryingTheAddress(): void
    {
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        $this->completeFlow($this->newSession($p['keys']['test'], 'forget.me@example.be')['session_id']);
        $this->completeFlow($this->newSession($p['keys']['test'], 'keep.me@example.be')['session_id']);
        self::assertSame(2, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries')['n']);
        $this->api($p['keys']['test'], 'DELETE', '/api/v1/verifications?email=forget.me@example.be');
        self::assertSame(1, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries')['n']);
    }

    public function testNoEndpointMeansNoDelivery(): void
    {
        $p = $this->createProject();
        $this->completeFlow($this->newSession($p['keys']['test'])['session_id']);
        self::assertSame(0, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries')['n']);
        // Les endpoints de production ne reçoivent pas les événements de la sandbox.
        $live = $this->createProject(webhooks: ['live' => 'https://shop.example/live'], name: 'Live');
        $this->completeFlow($this->newSession($live['keys']['test'])['session_id']);
        self::assertSame(0, (int) $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries')['n']);
    }

    /** @param array<string, list<string>> $dns */
    private function dispatcher(WebhookTransport $transport, array $dns): WebhookDispatcher
    {
        /** @var array{max_attempts: int, base_delay: int, max_delay: int, connect_timeout: int, timeout: int} $config */
        $config = $this->app->config->get('verification.webhooks');
        $db = $this->app->db();

        return new WebhookDispatcher(
            new WebhookEndpointRepository($db),
            new WebhookDeliveryRepository($db),
            new ProjectRepository($db, $this->app->crypto()),
            $this->app->crypto(),
            new UrlGuard(false, static fn (string $host): array => $dns[$host] ?? []),
            $transport,
            $this->app->queue(),
            $this->app->logger(),
            $config,
        );
    }

    /** @return array<string, mixed> */
    private function delivery(): array
    {
        return $this->app->db()->fetchOne('SELECT * FROM webhook_deliveries ORDER BY id DESC LIMIT 1') ?? [];
    }

    private function makeDue(): void
    {
        $this->app->db()->execute('UPDATE webhook_deliveries SET next_attempt_at = UTC_TIMESTAMP()');
    }
}

/** Transport de test : enregistre les appels et renvoie les statuts prévus (null = délai dépassé). */
final class RecordingTransport implements WebhookTransport
{
    /** @var list<array{url: string, ip: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    /** @param list<?int> $statuses */
    public function __construct(private array $statuses)
    {
    }

    public function post(string $url, string $ip, array $headers, string $body, int $connectTimeout, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'ip' => $ip, 'headers' => $headers, 'body' => $body];
        $status = array_shift($this->statuses);

        return $status === null ? ['status' => null, 'error' => 'timeout'] : ['status' => $status, 'error' => null];
    }
}
