<?php

declare(strict_types=1);

namespace App\Verification\Methods;

use App\Core\Logger;
use App\Models\VerificationSession;
use App\Verification\Biometrics\AnalysisResult;
use App\Verification\Biometrics\BiometricsClient;
use App\Verification\Biometrics\BiometricsException;
use App\Verification\Biometrics\CapturePayload;
use App\Verification\VerificationMethodInterface;
use App\Verification\VerificationOutcome;

/**
 * Méthode « pièce d'identité + visage », 100 % locale : les images sont relayées, en mémoire, au
 * microservice biometrics/ (127.0.0.1), qui renvoie seulement { age, doc_expired, face_match_score,
 * liveness_passed, mrz_valid, reasons }. Aucun service externe.
 *
 * Décision (seuils dans .env, bientôt dans l'administration, phase 7) :
 * 1. MRZ illisible ou chiffres de contrôle faux → échec « document_unreadable » ;
 * 2. document expiré → échec « document_expired » ;
 * 3. contrôle du vivant échoué → échec « liveness_failed » ;
 * 4. correspondance du visage ≥ seuil d'acceptation → résultat (âge ≥ âge minimal de la session) ;
 * 5. entre le seuil de revue et le seuil d'acceptation → revue manuelle si le projet l'a choisie ;
 * 6. sinon → échec « face_mismatch ».
 * Panne technique (service arrêté, occupé, délai dépassé, réponse non signée ou hors contrat) : AUCUNE
 * décision. BiometricsException est relancée, la session reste ouverte (ni échec, ni webhook, ni blocage
 * de l'adresse) et la page propose de recommencer, dans la limite des tirages de défis de la session.
 * Demande refusée par le service (422, capture inexploitable) → échec « capture_rejected ».
 */
final class LocalBiometricsProvider implements VerificationMethodInterface
{
    public const ID = 'id_document_face';

    /**
     * @param array<string, int|float> $liveness seuils transmis au microservice
     */
    public function __construct(
        private readonly ?BiometricsClient $client,
        private readonly float $matchThreshold,
        private readonly float $reviewThreshold,
        private readonly array $liveness,
        private readonly string $timezone,
        private readonly ?Logger $logger = null,
    ) {
        if ($reviewThreshold > $matchThreshold) {
            throw new \InvalidArgumentException('Le seuil de revue doit être inférieur ou égal au seuil d\'acceptation.');
        }
    }

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(bool $livemode): bool
    {
        return $this->client !== null;
    }

    public function requiresTopLevelWindow(): bool
    {
        return false;
    }

    /**
     * @param array{capture?: CapturePayload, challenge?: list<string>, review_allowed?: bool} $input
     *
     * @throws BiometricsException panne technique du microservice (aucune décision)
     */
    public function verify(VerificationSession $session, array $input): VerificationOutcome
    {
        $capture = $input['capture'] ?? null;
        $challenge = $input['challenge'] ?? null;
        if ($this->client === null || !$capture instanceof CapturePayload || !is_array($challenge) || $challenge === []) {
            throw new \InvalidArgumentException('Capture absente.');
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
        try {
            $result = $this->client->analyze($capture->toRequest($challenge, $today, $this->liveness));
        } catch (BiometricsException $e) {
            // Code stable seulement : jamais d'image, de réponse ni de corps dans le journal.
            $this->logger?->warning('biometrics_call_failed', ['reason' => $e->reason, 'detail' => $e->detail]);
            if ($e->reason === BiometricsException::REJECTED) {
                return VerificationOutcome::failed('capture_rejected');
            }

            // Panne technique : rien n'est décidé, la session reste ouverte (voir l'en-tête).
            throw $e;
        }

        return $this->decide($result, $session->minAge, (bool) ($input['review_allowed'] ?? false));
    }

    /**
     * Motifs du service indiquant que les faces envoyées ne forment pas UNE même pièce (recto sans document,
     * portrait hors de sa place, champs imprimés ≠ MRZ, type de document ≠ type de MRZ) : exigence E1 de l'audit.
     */
    public const INCONSISTENT_REASONS = ['document_not_detected', 'document_portrait_not_found', 'document_sides_mismatch',
        'document_front_unreadable', 'document_type_mismatch'];

    /**
     * Revue manuelle : DÉSACTIVÉE en V1 (audit de la phase 3, E3). Sans image, l'opérateur ne verrait que le
     * score, déjà comparé au seuil : « approuver » reviendrait à abaisser le seuil sous celui de même personne.
     * À réactiver seulement après une décision juridique sur la conservation chiffrée des images.
     */
    public const MANUAL_REVIEW_ENABLED = false;

    public function decide(AnalysisResult $result, int $minAge, bool $reviewAllowed): VerificationOutcome
    {
        if (in_array('document_unsupported', $result->reasons, true)) {
            return VerificationOutcome::failed('document_unsupported');
        }
        if (array_intersect(self::INCONSISTENT_REASONS, $result->reasons) !== []) {
            return VerificationOutcome::failed('document_inconsistent');
        }
        if (!$result->mrzValid || $result->age === null) {
            return VerificationOutcome::failed('document_unreadable');
        }
        if ($result->docExpired === true) {
            return VerificationOutcome::failed('document_expired');
        }
        if (!$result->livenessPassed) {
            return VerificationOutcome::failed('liveness_failed');
        }
        if ($result->faceMatchScore === null) {
            return VerificationOutcome::failed('face_not_found');
        }
        $isAdult = $result->age >= $minAge;
        if ($result->faceMatchScore >= $this->matchThreshold) {
            return VerificationOutcome::verified($isAdult);
        }
        if (self::MANUAL_REVIEW_ENABLED && $reviewAllowed && $result->faceMatchScore >= $this->reviewThreshold) {
            return VerificationOutcome::review($isAdult, $result->faceMatchScore, $result->livenessPassed, $result->reasons);
        }

        return VerificationOutcome::failed('face_mismatch');
    }
}
