<?php

declare(strict_types=1);

namespace Tests\Support;

/** Stockage de sessions en mémoire pour les tests unitaires. */
final class ArraySessionHandler implements \SessionHandlerInterface
{
    /** @var array<string, string> */
    public array $store = [];

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
        return $this->store[$id] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        $this->store[$id] = $data;

        return true;
    }

    public function destroy(string $id): bool
    {
        unset($this->store[$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}
