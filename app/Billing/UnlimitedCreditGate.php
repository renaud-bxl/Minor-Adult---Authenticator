<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Project;

/** Phase 2 : aucune facturation, tout est autorisé. Remplacé par le décompte des crédits en phase 6. */
final class UnlimitedCreditGate implements CreditGateInterface
{
    public function allowsNewVerification(Project $project, bool $livemode): bool
    {
        return true;
    }
}
