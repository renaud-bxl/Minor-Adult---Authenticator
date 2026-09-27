<?php

declare(strict_types=1);

namespace App\Verification\Webhooks;

use App\Core\Crypto;
use App\Core\Logger;
use App\Core\RedisQueue;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\WebhookDeliveryRepository;
use App\Models\WebhookEndpointRepository;
use App\Verification\UrlGuard;

/**
 * Webhooks signés : mise en file (dans la transaction qui termine la session) puis livraison par le
 * worker, avec relances exponentielles.
 *
 * Idempotence : un événement a un identifiant unique (« evt_… »), livré au plus une fois par endpoint
 * avec succès ; nos relances renvoient le même identifiant, que le client doit dédupliquer.
 * La signature est recalculée à chaque tentative (horodatage frais, fenêtre anti-rejeu de 5 min).
 */
final class WebhookDispatcher
{
    public const COMPLETED = 'verification.completed';
    public const FAILED = 'verification.failed';

    /**
     * @param array{max_attempts: int, base_delay: int, max_delay: int, connect_timeout: int, timeout: int} $config
     */
    public function __construct(
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly ProjectRepository $projects,
        private readonly Crypto $crypto,
        private readonly UrlGuard $urls,
        private readonly WebhookTransport $transport,
        private readonly ?RedisQueue $queue,
        private readonly Logger $logger,
        private readonly array $config,
    ) {
    }

    /**
     * Crée l'événement et une livraison par endpoint actif du projet (même mode).
     *
     * @param array<string, mixed> $data
     * @return string|null identifiant de l'événement, ou null si aucun endpoint n'est configuré
     */
    public function enqueue(Project $project, bool $livemode, string $type, array $data, int $now): ?string
    {
        $endpoints = $this->endpoints->enabledFor($project->id, $livemode);
        if ($endpoints === []) {
            return null;
        }
        $eventId = 'evt_' . Crypto::randomAlnum(28);
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => $now,
            'livemode' => $livemode,
            'project' => $project->publicId,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($endpoints as $endpoint) {
            $this->deliveries->enqueue(
                $eventId,
                $type,
                (int) $endpoint['id'],
                $project->id,
                $this->crypto->encrypt($payload, 'webhook:' . $eventId),
                is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            );
        }

        return $eventId;
    }

    /** Réveille le worker (à appeler après validation de la transaction). */
    public function wake(): void
    {
        try {
            $this->queue?->wake();
        } catch (\Throwable $e) {
            // Sans réveil, le worker traite la livraison à sa prochaine scrutation (quelques secondes).
            $this->logger->warning('webhook_wake_failed', Logger::exceptionContext($e));
        }
    }

    /**
     * Livre les webhooks échus. Verrou de concurrence : le lot est réservé (lock_token) ; la réservation
     * de chaque livraison est prolongée juste avant son envoi, et abandonnée si un autre worker l'a
     * reprise entre-temps (lot plus long que la réservation) : jamais deux envois simultanés.
     *
     * @return int nombre de livraisons traitées
     */
    public function processDue(int $limit = 20): int
    {
        $lockSeconds = $this->lockSeconds();
        $rows = $this->deliveries->claimDue($limit, $lockSeconds);
        $handled = 0;
        foreach ($rows as $row) {
            $lock = $this->deliveries->renewLock((int) $row['id'], (string) $row['lock_token'], $lockSeconds);
            if ($lock !== null) {
                $this->deliver([...$row, 'lock_token' => $lock]);
                $handled++;
            }
        }

        return $handled;
    }

    /** Durée de réservation d'UNE livraison : délais réseau largement couverts (résolution DNS comprise). */
    private function lockSeconds(): int
    {
        return 2 * ($this->config['timeout'] + $this->config['connect_timeout']) + 30;
    }

    /** @param array<string, mixed> $row */
    private function deliver(array $row): void
    {
        $id = (int) $row['id'];
        $lock = (string) $row['lock_token'];
        $attempt = (int) $row['attempts'] + 1;
        $endpoint = $this->endpoints->findById((int) $row['endpoint_id']);
        $project = $this->projects->findById((int) $row['project_id']);
        if ($endpoint === null || $project === null || (int) $endpoint['enabled'] !== 1) {
            $this->deliveries->markAttemptFailed($id, $lock, null, 'endpoint_disabled', 0, true);

            return;
        }
        $url = (string) $endpoint['url'];

        // Contrôle SSRF à chaque envoi : les domaines autorisés ou le DNS ont pu changer.
        $urlError = $this->urls->checkUrl($url, $project->allowedOrigins);
        if ($urlError !== null) {
            $this->deliveries->markAttemptFailed($id, $lock, null, 'url_rejected_' . $urlError, 0, true);
            $this->logger->warning('webhook_url_rejected', ['delivery' => $id, 'reason' => $urlError]);

            return;
        }
        [$ip, $dnsError] = $this->urls->resolvePublic((string) parse_url($url, PHP_URL_HOST));
        if ($ip === null) {
            $this->retryOrFail($id, $lock, $attempt, null, (string) $dnsError);

            return;
        }

        try {
            $payload = $this->crypto->decrypt((string) $row['payload_enc'], 'webhook:' . $row['event_id']);
            $secrets = $this->projects->signingSecrets($project, (bool) $endpoint['livemode']);
        } catch (\Throwable $e) {
            $this->deliveries->markAttemptFailed($id, $lock, null, 'payload_unreadable', 0, true);
            $this->logger->error('webhook_payload_unreadable', ['delivery' => $id, ...Logger::exceptionContext($e)]);

            return;
        }

        $result = $this->transport->post($url, $ip, [
            'Content-Type' => 'application/json',
            WebhookSignature::HEADER => WebhookSignature::signAll($payload, $secrets, time()),
            'X-VeriAge-Event-Id' => (string) $row['event_id'],
            'X-VeriAge-Event-Type' => (string) $row['event_type'],
            'X-VeriAge-Delivery-Attempt' => (string) $attempt,
        ], $payload, $this->config['connect_timeout'], $this->config['timeout']);

        $status = $result['status'];
        if ($status !== null && $status >= 200 && $status < 300) {
            $this->deliveries->markDelivered($id, $lock, $status);
            $this->logger->info('webhook_delivered', ['delivery' => $id, 'status' => $status, 'attempt' => $attempt]);

            return;
        }
        $this->retryOrFail($id, $lock, $attempt, $status, $result['error'] ?? ('http_' . $status));
    }

    private function retryOrFail(int $id, string $lock, int $attempt, ?int $status, string $error): void
    {
        $final = $attempt >= $this->config['max_attempts'] || $error === 'private_address';
        $delay = $final ? 0 : self::backoff($attempt, $this->config['base_delay'], $this->config['max_delay']);
        $this->deliveries->markAttemptFailed($id, $lock, $status, $error, $delay, $final);
        $this->logger->warning('webhook_attempt_failed', ['delivery' => $id, 'attempt' => $attempt, 'error' => $error, 'final' => $final]);
    }

    /** Délai avant la tentative suivante : base × 2^(n-1), plafonné, avec ±10 % d'aléa (étalement). */
    public static function backoff(int $attempt, int $base, int $max): int
    {
        $delay = min($max, $base * (2 ** max(0, min(30, $attempt - 1))));
        $jitter = (int) floor($delay / 10);

        return max(1, $delay + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0));
    }
}
