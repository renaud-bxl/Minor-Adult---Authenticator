<?php

declare(strict_types=1);

namespace App\Core;

use Predis\ClientInterface;

/**
 * Stockage des sessions dans Redis (predis). L'expiration est déléguée au TTL Redis : chaque
 * écriture repousse l'échéance d'inactivité ; il n'y a donc rien à faire au ramasse-miettes.
 */
final class RedisSessionHandler implements \SessionHandlerInterface
{
    private const KEY_PREFIX = 'sess:';

    public function __construct(private readonly ClientInterface $redis, private readonly int $ttlSeconds)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return (string) $this->redis->get(self::KEY_PREFIX . $id);
    }

    public function write(string $id, string $data): bool
    {
        $this->redis->setex(self::KEY_PREFIX . $id, $this->ttlSeconds, $data);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->redis->del([self::KEY_PREFIX . $id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}
