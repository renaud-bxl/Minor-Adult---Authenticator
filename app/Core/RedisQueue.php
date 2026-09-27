<?php

declare(strict_types=1);

namespace App\Core;

use Predis\ClientInterface;

/**
 * File de travaux Redis fiable, aux charges utiles chiffrées (AES-256-GCM) : Redis ne contient
 * jamais de donnée personnelle en clair (ex. destinataire d'un e-mail).
 *
 * - push : LPUSH sur « queue:{nom} » (ou ZADD sur « queue:{nom}:delayed » pour un envoi différé),
 *   puis réveil du worker (« queue:wake ») ;
 * - reserve : RPOPLPUSH vers la liste de traitement propre au worker ; ack la retire. Un travail
 *   réservé par un worker arrêté brutalement est remis en file au redémarrage de ce worker (recover) ;
 *   la livraison est donc « au moins une fois ».
 */
final class RedisQueue
{
    public const WAKE_KEY = 'queue:wake';

    private const PROMOTE_SCRIPT = <<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
        for _, job in ipairs(due) do
            redis.call('ZREM', KEYS[1], job)
            redis.call('LPUSH', KEYS[2], job)
        end
        return #due
        LUA;

    public function __construct(private readonly ClientInterface $redis, private readonly Crypto $crypto)
    {
    }

    /** @param array<string, mixed> $job */
    public function push(string $queue, array $job, int $delaySeconds = 0): void
    {
        $payload = base64_encode($this->crypto->encrypt(json_encode($job, JSON_THROW_ON_ERROR), 'queue:' . $queue));
        if ($delaySeconds > 0) {
            $this->redis->zadd($this->key($queue) . ':delayed', [$payload => time() + $delaySeconds]);

            return;
        }
        $this->redis->lpush($this->key($queue), [$payload]);
        $this->wake();
    }

    /** Signale au worker qu'un travail est disponible (liste bornée). */
    public function wake(): void
    {
        $this->redis->lpush(self::WAKE_KEY, ['1']);
        $this->redis->ltrim(self::WAKE_KEY, 0, 9);
    }

    /** Attend un réveil au plus $timeout secondes. */
    public function waitForWake(int $timeout): void
    {
        $this->redis->brpop([self::WAKE_KEY], $timeout);
    }

    /** Déplace les travaux différés échus dans la file. */
    public function promoteDelayed(string $queue): int
    {
        return (int) $this->redis->eval(self::PROMOTE_SCRIPT, 2, $this->key($queue) . ':delayed', $this->key($queue), time());
    }

    /**
     * Réserve le prochain travail pour ce worker.
     *
     * @return array{raw: string, job: array<string, mixed>}|null
     */
    public function reserve(string $queue, string $workerId): ?array
    {
        $raw = $this->redis->rpoplpush($this->key($queue), $this->processingKey($queue, $workerId));
        if (!is_string($raw)) {
            return null;
        }
        try {
            $job = json_decode($this->crypto->decrypt((string) base64_decode($raw, true), 'queue:' . $queue), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Travail illisible (clé changée, donnée altérée) : écarté, jamais rejoué en boucle.
            $this->ack($queue, $workerId, $raw);

            return ['raw' => $raw, 'job' => []];
        }

        return ['raw' => $raw, 'job' => is_array($job) ? $job : []];
    }

    public function ack(string $queue, string $workerId, string $raw): void
    {
        $this->redis->lrem($this->processingKey($queue, $workerId), 1, $raw);
    }

    /** Au démarrage d'un worker : ses travaux réservés et non acquittés reviennent en file. */
    public function recover(string $queue, string $workerId): int
    {
        $count = 0;
        while ($this->redis->rpoplpush($this->processingKey($queue, $workerId), $this->key($queue)) !== null) {
            $count++;
        }

        return $count;
    }

    public function size(string $queue): int
    {
        return (int) $this->redis->llen($this->key($queue)) + (int) $this->redis->zcard($this->key($queue) . ':delayed');
    }

    private function key(string $queue): string
    {
        return 'queue:' . $queue;
    }

    private function processingKey(string $queue, string $workerId): string
    {
        return 'queue:' . $queue . ':processing:' . $workerId;
    }
}
