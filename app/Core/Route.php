<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    private readonly string $regex;

    /** @var list<class-string> */
    private array $middleware;

    /**
     * @param list<string>                      $methods
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array $handler,
        array $middleware = [],
    ) {
        $this->middleware = $middleware;
        $this->regex = self::compile($pattern);
    }

    /** Ajoute des middlewares propres à cette route (exécutés après ceux du groupe). */
    public function middleware(string ...$middleware): self
    {
        /** @var list<class-string> $middleware */
        array_push($this->middleware, ...$middleware);

        return $this;
    }

    /** @return list<class-string> */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /** @return array<string, string>|null paramètres nommés, ou null si le chemin ne correspond pas */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}/i',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')',
            // Échappe tout ce qui n'est pas un paramètre avant de substituer les paramètres.
            implode('', array_map(
                static fn (string $part): string => str_starts_with($part, '{') ? $part : preg_quote($part, '#'),
                preg_split('/(\{[^{}]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: ['/'],
            )),
        );

        return '#^' . $regex . '$#u';
    }
}
