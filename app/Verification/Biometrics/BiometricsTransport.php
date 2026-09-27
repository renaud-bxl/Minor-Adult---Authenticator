<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/** Transport HTTP vers le microservice local (remplaçable par un faux dans les tests). */
interface BiometricsTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} en-têtes en minuscules
     *
     * @throws BiometricsException service injoignable ou délai dépassé
     */
    public function send(string $method, string $url, array $headers, string $body): array;
}
