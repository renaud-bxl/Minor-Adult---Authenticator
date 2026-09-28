<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\VerificationSession;
use App\Verification\Biometrics\AnalysisResult;
use App\Verification\Biometrics\BiometricsClient;
use App\Verification\Biometrics\BiometricsException;
use App\Verification\Biometrics\CapturePayload;
use App\Verification\Methods\LocalBiometricsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBiometricsService;

/** Décision de la méthode « pièce d'identité + visage » : seuils, revue manuelle, âge, expiration, pannes. */
final class LocalBiometricsProviderTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789abcdef-0123456789';

    private static function provider(?FakeBiometricsService $fake = null): LocalBiometricsProvider
    {
        $client = $fake === null ? null : new BiometricsClient($fake, 'http://127.0.0.1:8765', self::SECRET);

        return new LocalBiometricsProvider($client, 0.40, 0.30, ['yaw_threshold' => 0.28], 'Europe/Brussels');
    }

    /** @return iterable<string, array{array<string, mixed>, int, bool, string}> */
    public static function decisions(): iterable
    {
        $r = FakeBiometricsService::result(...);
        yield 'majeur, visage conforme' => [$r(age: 30, score: 0.82), 18, false, 'verified:adult'];
        yield 'mineur, visage conforme' => [$r(age: 17, score: 0.82), 18, false, 'verified:minor'];
        yield '18 ans pile' => [$r(age: 18, score: 0.5), 18, false, 'verified:adult'];
        yield 'majeur à 18 mais pas à 21' => [$r(age: 19, score: 0.5), 21, false, 'verified:minor'];
        yield 'score égal au seuil' => [$r(score: 0.40), 18, false, 'verified:adult'];
        yield 'sous le seuil, projet en échec' => [$r(score: 0.35), 18, false, 'failed:face_mismatch'];
        yield 'sous le seuil, projet en revue : revue désactivée en V1' => [$r(score: 0.35), 18, true, 'failed:face_mismatch'];
        yield 'recto sans document (attaque A)' => [$r(score: null, reasons: ['document_not_detected']), 18, false, 'failed:document_inconsistent'];
        yield 'faces de deux pièces (attaque B)' => [$r(score: null, reasons: ['document_sides_mismatch']), 18, false, 'failed:document_inconsistent'];
        yield 'portrait hors de sa place' => [$r(score: null, reasons: ['document_portrait_not_found']), 18, false, 'failed:document_inconsistent'];
        yield 'type de document incohérent' => [$r(age: null, expired: null, mrz: false, score: null, reasons: ['document_type_mismatch']), 18, false, 'failed:document_inconsistent'];
        yield 'ancienne carte française' => [$r(age: null, expired: null, mrz: false, score: null, reasons: ['document_unsupported']), 18, false, 'failed:document_unsupported'];
        yield 'portrait du document animé (attaque C)' => [$r(score: 0.96, liveness: false, reasons: ['face_identical_to_document']), 18, false, 'failed:liveness_failed'];
        // Défense en profondeur : un motif de liaison suffit, même si le service renvoyait un score.
        yield 'motif de liaison malgré un score' => [$r(score: 0.9, reasons: ['document_sides_mismatch']), 18, false, 'failed:document_inconsistent'];
        yield 'sous le seuil de revue' => [$r(score: 0.29), 18, true, 'failed:face_mismatch'];
        yield 'MRZ illisible' => [$r(age: null, expired: null, mrz: false, reasons: ['mrz_not_found']), 18, true, 'failed:document_unreadable'];
        yield 'document expiré' => [$r(expired: true, reasons: ['document_expired']), 18, true, 'failed:document_expired'];
        yield 'vivant échoué' => [$r(liveness: false, reasons: ['liveness_challenge_failed']), 18, true, 'failed:liveness_failed'];
        yield 'pas de visage sur le document' => [$r(score: null, reasons: ['face_not_found_document']), 18, true, 'failed:face_not_found'];
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('decisions')]
    public function testDecision(array $response, int $minAge, bool $reviewAllowed, string $expected): void
    {
        $outcome = self::provider()->decide(AnalysisResult::fromArray($response), $minAge, $reviewAllowed);
        $actual = match (true) {
            $outcome->needsReview => 'review:' . ($outcome->isAdult ? 'adult' : 'minor'),
            $outcome->verified => 'verified:' . ($outcome->isAdult ? 'adult' : 'minor'),
            default => 'failed:' . $outcome->failureReason,
        };
        self::assertSame($expected, $actual);
    }

    public function testManualReviewIsDisabledInV1(): void
    {
        self::assertFalse(LocalBiometricsProvider::MANUAL_REVIEW_ENABLED);
        $outcome = self::provider()->decide(AnalysisResult::fromArray(FakeBiometricsService::result(score: 0.33)), 18, true);
        self::assertFalse($outcome->needsReview);
        self::assertSame('face_mismatch', $outcome->failureReason);
    }

    public function testAvailabilityAndThresholdOrder(): void
    {
        self::assertFalse(self::provider()->isAvailable(true));
        self::assertTrue(self::provider(new FakeBiometricsService(self::SECRET))->isAvailable(false));
        self::assertFalse(self::provider()->requiresTopLevelWindow());
        $this->expectException(\InvalidArgumentException::class);
        new LocalBiometricsProvider(null, 0.3, 0.4, [], 'UTC');
    }

    public function testServiceFailureIsATechnicalFailure(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $fake->down = true;
        try {
            self::provider($fake)->verify(self::session(), ['capture' => self::capture(), 'challenge' => ['blink']]);
            self::fail('Panne technique : aucune décision, exception attendue.');
        } catch (BiometricsException $e) {
            self::assertSame(BiometricsException::UNAVAILABLE, $e->reason);
        }
        $fake->down = false;
        $fake->status = 422;
        $fake->response = ['error' => 'image_invalid'];
        self::assertSame('capture_rejected', self::provider($fake)->verify(self::session(), ['capture' => self::capture(), 'challenge' => ['blink']])->failureReason);
    }

    public function testRequestCarriesServerChallengeAndBrusselsDate(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        self::provider($fake)->verify(self::session(), ['capture' => self::capture(), 'challenge' => ['turn_left', 'blink']]);
        self::assertSame(['turn_left', 'blink'], $fake->lastRequest['selfie']['challenge']);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->format('Y-m-d'), $fake->lastRequest['reference_date']);
        self::assertSame(['yaw_threshold' => 0.28], $fake->lastRequest['liveness']);
        self::assertSame(['reference_date', 'document', 'selfie', 'liveness'], array_keys($fake->lastRequest));
    }

    public function testMissingCaptureIsAnInputError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::provider(new FakeBiometricsService(self::SECRET))->verify(self::session(), []);
    }

    public static function session(int $minAge = 18): VerificationSession
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new VerificationSession(1, 'vs_' . str_repeat('a', 32), 1, null, false, str_repeat('0', 64), 'x', $minAge, null, 'fr', null,
            'pending', $now, null, null, 0, 1, $now, $now, false, null, 'none', null, null, null, null, $now->modify('+30 minutes'), null, $now);
    }

    public static function capture(): CapturePayload
    {
        $jpeg = CaptureTest::jpeg(640, 480);
        $frame = CaptureTest::jpeg(160, 120);

        return CapturePayload::fromJson(json_encode([
            'document_type' => 'passport',
            'front' => base64_encode($jpeg),
            'back' => null,
            'frames' => [['t' => 0, 'step' => 0, 'image' => base64_encode($frame)], ['t' => 200, 'step' => 1, 'image' => base64_encode($frame)]],
        ], JSON_THROW_ON_ERROR), 1, CaptureTest::LIMITS);
    }
}
