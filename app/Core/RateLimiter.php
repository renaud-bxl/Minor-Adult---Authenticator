<?php

declare(strict_types=1);

namespace App\Core;

use Predis\ClientInterface;

/**
 * Limitation de débit à fenêtre glissante (journal horodaté dans un ZSET Redis).
 *
 * L'opération est atomique (script Lua) et horodatée par l'horloge Redis, ce qui reste exact avec
 * plusieurs serveurs PHP. Une tentative refusée n'est pas enregistrée : le blocage se lève dès que
 * la plus ancienne tentative sort de la fenêtre. Les identifiants (IP, e-mail) sont transformés en
 * empreinte HMAC avant d'être utilisés comme clé : Redis ne contient aucune donnée personnelle.
 */
final class RateLimiter
{
    private const SCRIPT = <<<'LUA'
        -- Millisecondes : 13 chiffres, représentés exactement lors des conversions nombre -> chaîne de Lua.
        local t = redis.call('TIME')
        local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
        local window = tonumber(ARGV[2]) * 1000
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
        local count = redis.call('ZCARD', KEYS[1])
        if count < tonumber(ARGV[1]) then
            redis.call('ZADD', KEYS[1], now, now .. '-' .. ARGV[3])
            redis.call('PEXPIRE', KEYS[1], window)
            return {1, count + 1, 0}
        end
        local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
        local retry = math.ceil((tonumber(oldest[2]) + window - now) / 1000)
        return {0, count, math.max(retry, 1)}
        LUA;

    public function __construct(private readonly ClientInterface $redis, private readonly Crypto $crypto)
    {
    }

    /**
     * Enregistre une tentative si le quota le permet.
     *
     * @param string $bucket     nom du compteur (ex. « login_email »)
     * @param string $identifier valeur limitée (IP, e-mail…), jamais stockée en clair
     */
    public function attempt(string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        /** @var array{0: int, 1: int, 2: int} $result */
        $result = $this->redis->eval(self::SCRIPT, 1, $this->key($bucket, $identifier), $maxAttempts, $windowSeconds, bin2hex(random_bytes(6)));

        return new RateLimitResult(
            allowed: (int) $result[0] === 1,
            remaining: max(0, $maxAttempts - (int) $result[1]),
            retryAfter: (int) $result[2],
        );
    }

    /** Remet un compteur à zéro (ex. après une connexion réussie). */
    public function clear(string $bucket, string $identifier): void
    {
        $this->redis->del([$this->key($bucket, $identifier)]);
    }

    private function key(string $bucket, string $identifier): string
    {
        return 'rl:' . $bucket . ':' . $this->crypto->fingerprint($bucket . "\0" . $identifier);
    }
}
