<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Project;

/**
 * Point d'extension de la facturation (phase 6) : autorise ou non une nouvelle vérification
 * facturable. Refus → 402 « insufficient_credits ». Les réutilisations (non facturées) et la sandbox
 * ne passent jamais par ce contrôle.
 */
interface CreditGateInterface
{
    public function allowsNewVerification(Project $project, bool $livemode): bool;
}
