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
 * Service indisponible → échec technique « biometrics_unavailable » (jamais réutilisé ni facturé comme
 * un résultat ; l'utilisateur peut recommencer depuis le site).
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

            return VerificationOutcome::failed(match ($e->reason) {
                BiometricsException::REJECTED => 'capture_rejected',
                default => 'biometrics_unavailable',
            });
        }

        return $this->decide($result, $session->minAge, (bool) ($input['review_allowed'] ?? false));
    }

    public function decide(AnalysisResult $result, int $minAge, bool $reviewAllowed): VerificationOutcome
    {
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
        if ($reviewAllowed && $result->faceMatchScore >= $this->reviewThreshold) {
            return VerificationOutcome::review($isAdult, $result->faceMatchScore, $result->livenessPassed, $result->reasons);
        }

        return VerificationOutcome::failed('face_mismatch');
    }
}
