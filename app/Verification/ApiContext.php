<?php

declare(strict_types=1);

namespace App\Verification;

use App\Models\Project;

/**
 * Appelant authentifié de l'API : projet, mode (sandbox « sk_test_ » ou production « sk_live_ ») et
 * clé utilisée. Toute lecture ou écriture de l'API est cloisonnée par (projet, mode) : aucune
 * donnée d'un autre client, ni de l'autre mode, n'est jamais accessible (pas d'IDOR).
 */
final class ApiContext
{
    public function __construct(
        public readonly Project $project,
        public readonly bool $livemode,
        public readonly int $keyId,
        public readonly string $keyLast4,
    ) {
    }
}
