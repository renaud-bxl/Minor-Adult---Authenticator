<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Erreur HTTP « attendue » (404, 405, 419, 429…) convertie en page ou en JSON d'erreur.
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(private readonly int $status, private readonly array $headers = [])
    {
        parent::__construct('HTTP ' . $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
