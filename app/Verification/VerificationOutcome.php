<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * Résultat d'une méthode de vérification : uniquement le booléen de majorité (relatif à l'âge
 * minimal demandé), un motif d'échec, ou une mise en revue manuelle. Jamais d'âge exact, de date de
 * naissance ni d'identité.
 */
final class VerificationOutcome
{
    /**
     * @param array{face_match_score: ?float, liveness_passed: bool, reasons: list<string>}|null $evidence
     *        signaux de la décision automatique conservés pour la revue (aucune donnée d'identité)
     */
    private function __construct(
        public readonly bool $verified,
        public readonly ?bool $isAdult,
        public readonly ?string $failureReason,
        public readonly bool $needsReview = false,
        public readonly ?array $evidence = null,
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

    /**
     * Décision automatique incertaine (correspondance du visage entre le seuil de revue et le seuil
     * d'acceptation) et projet réglé sur « revue manuelle » : la session attend un opérateur.
     *
     * @param list<string> $reasons
     */
    public static function review(bool $provisionalIsAdult, ?float $faceMatchScore, bool $livenessPassed, array $reasons): self
    {
        foreach ($reasons as $reason) {
            if (preg_match('/^[a-z_]{1,40}$/D', $reason) !== 1) {
                throw new \InvalidArgumentException('Motif invalide.');
            }
        }

        return new self(false, $provisionalIsAdult, null, true, [
            'face_match_score' => $faceMatchScore,
            'liveness_passed' => $livenessPassed,
            'reasons' => array_values($reasons),
        ]);
    }
}
