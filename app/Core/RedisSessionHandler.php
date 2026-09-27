<?php

declare(strict_types=1);

namespace App\Core;

use Predis\ClientInterface;

/**
 * Stockage des sessions dans Redis (predis). L'expiration est déléguée au TTL Redis : chaque
 * écriture repousse l'échéance d'inactivité ; il n'y a donc rien à faire au ramasse-miettes.
 *
 * Pas de verrou (requêtes concurrentes : la dernière écriture l'emporte), mais pas de résurrection :
 * une session lue pendant cette requête n'est réécrite que si elle existe encore (SET … XX). Une
 * requête lente ne peut donc pas recréer une session détruite entre-temps (déconnexion dans un autre
 * onglet, régénération, expiration).
 */
final class RedisSessionHandler implements \SessionHandlerInterface
{
    private const KEY_PREFIX = 'sess:';

    /** @var array<string, true> identifiants lus (donc existants) pendant cette requête */
    private array $existing = [];

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
        $data = (string) $this->redis->get(self::KEY_PREFIX . $id);
        if ($data !== '') {
            $this->existing[$id] = true;
        }

        return $data;
    }

    /** Renvoie false si la session lue a disparu entre-temps (elle n'est alors pas recréée). */
    public function write(string $id, string $data): bool
    {
        if (isset($this->existing[$id])) {
            return $this->redis->set(self::KEY_PREFIX . $id, $data, 'EX', $this->ttlSeconds, 'XX') !== null;
        }
        $this->redis->setex(self::KEY_PREFIX . $id, $this->ttlSeconds, $data);
        $this->existing[$id] = true;

        return true;
    }

    public function destroy(string $id): bool
    {
        unset($this->existing[$id]);
        $this->redis->del([self::KEY_PREFIX . $id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}
