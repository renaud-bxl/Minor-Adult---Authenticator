<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration en lecture seule, accessible en notation pointée (« security.session.idle_minutes »).
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    /**
     * Charge chaque fichier config/{nom}.php sous la clé {nom}.
     *
     * @param array<string, mixed> $overrides surcharges en notation pointée (tests)
     */
    public static function fromDirectory(string $directory, array $overrides = []): self
    {
        $items = [];
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }
        foreach ($overrides as $key => $value) {
            self::setIn($items, $key, $value);
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param array<string, mixed> $items */
    private static function setIn(array &$items, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $node = &$items;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node[$last] = $value;
    }
}
