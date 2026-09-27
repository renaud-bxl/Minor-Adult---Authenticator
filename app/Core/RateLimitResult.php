<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        /** Secondes avant qu'une nouvelle tentative soit acceptée (0 si autorisée). */
        public readonly int $retryAfter,
    ) {
    }
}
