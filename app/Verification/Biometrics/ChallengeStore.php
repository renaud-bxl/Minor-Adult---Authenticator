<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

use App\Core\Crypto;
use Predis\ClientInterface;

/**
 * Défis du contrôle du vivant, TIRÉS CÔTÉ SERVEUR (random_int) et conservés dans Redis (jamais confiés au
 * navigateur comme source de vérité) : suite de défis parmi « tourner la tête à gauche », « à droite »,
 * « fermer les yeux », « ouvrir la bouche », répétitions permises mais jamais deux défis identiques de suite
 * (4 défis : 4 × 3 × 3 × 3 = 108 suites), identifiant de capture à usage unique, heure d'émission.
 * Limite (documentée) : le défi est connu avant la capture ; il ne gêne pas une animation pilotée en direct.
 *
 * Nombre de tirages borné par session : sans cette borne, un fraudeur pourrait redemander des défis
 * jusqu'à obtenir l'ordre de sa vidéo préenregistrée.
 */
final class ChallengeStore
{
    public const ACTIONS = ['turn_left', 'turn_right', 'blink', 'open_mouth'];

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $ttl = 300,
        private readonly int $maxAttempts = 3,
    ) {
    }

    /**
     * @return array{capture_id: string, steps: list<string>, issued_ms: int}|null null : tirages épuisés
     */
    public function issue(string $sessionId, int $stepCount, int $nowMs): ?array
    {
        $attemptsKey = 'capture_attempts:' . $sessionId;
        $attempts = (int) $this->redis->incr($attemptsKey);
        $this->redis->expire($attemptsKey, 7200);
        if ($attempts > $this->maxAttempts) {
            return null;
        }
        $steps = [];
        for ($i = 0, $count = max(1, min($stepCount, 6)); $i < $count; $i++) {
            // Aléa cryptographique ; jamais le même défi que le précédent.
            $choices = array_values(array_diff(self::ACTIONS, $steps === [] ? [] : [$steps[$i - 1]]));
            $steps[] = $choices[random_int(0, count($choices) - 1)];
        }
        $record = [
            'capture_id' => Crypto::randomAlnum(24),
            'steps' => $steps,
            'issued_ms' => $nowMs,
        ];
        $this->redis->setex('capture:' . $sessionId, $this->ttl, json_encode($record, JSON_THROW_ON_ERROR));

        return $record;
    }

    public function remainingAttempts(string $sessionId): int
    {
        return max(0, $this->maxAttempts - (int) $this->redis->get('capture_attempts:' . $sessionId));
    }

    /**
     * Consomme le défi (une seule fois, même si la suite échoue).
     *
     * @return array{capture_id: string, steps: list<string>, issued_ms: int}|null
     */
    public function consume(string $sessionId, string $captureId): ?array
    {
        $key = 'capture:' . $sessionId;
        $results = $this->redis->transaction(static function ($tx) use ($key): void {
            $tx->get($key);
            $tx->del([$key]);
        });
        $raw = is_array($results) ? ($results[0] ?? null) : null;
        if (!is_string($raw)) {
            return null;
        }
        $record = json_decode($raw, true);
        if (!is_array($record) || !is_string($record['capture_id'] ?? null) || !hash_equals($record['capture_id'], $captureId)) {
            return null;
        }

        /** @var array{capture_id: string, steps: list<string>, issued_ms: int} $record */
        return $record;
    }
}
