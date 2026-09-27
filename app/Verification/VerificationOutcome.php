<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * Résultat d'une méthode de vérification : uniquement le booléen de majorité (relatif à l'âge
 * minimal demandé) ou un motif d'échec. Jamais d'âge exact, de date de naissance ni d'identité.
 */
final class VerificationOutcome
{
    private function __construct(
        public readonly bool $verified,
        public readonly ?bool $isAdult,
        public readonly ?string $failureReason,
    ) {
    }

    /** Vérification aboutie : la personne a (ou n'a pas) l'âge minimal requis. */
    public static function verified(bool $isAdult): self
    {
        return new self(true, $isAdult, null);
    }

    /** Le processus n'a pas permis de conclure (document illisible, contrôle du vivant échoué…). */
    public static function failed(string $reason): self
    {
        if (preg_match('/^[a-z_]{1,32}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Motif d\'échec invalide.');
        }

        return new self(false, null, $reason);
    }
}
