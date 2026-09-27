<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Échec de l'appel au microservice biométrique (indisponible, délai dépassé, réponse non signée ou hors
 * contrat, demande refusée). Le message est un code stable, jamais une donnée reçue ou envoyée.
 */
final class BiometricsException extends \RuntimeException
{
    public const UNAVAILABLE = 'biometrics_unavailable';
    public const BUSY = 'biometrics_busy';
    public const PROTOCOL = 'biometrics_protocol_error';
    public const REJECTED = 'biometrics_rejected';

    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($reason);
    }
}
