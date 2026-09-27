<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\Biometrics\AnalysisResult;
use App\Verification\Biometrics\BiometricsClient;
use App\Verification\Biometrics\BiometricsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBiometricsService;

/**
 * Client du microservice : signature HMAC des requêtes, réponse signée et liée au nonce EXIGÉE,
 * contrat de réponse strict (aucun champ en plus), boucle locale imposée, erreurs → codes stables.
 */
final class BiometricsClientTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789abcdef-0123456789';

    private function client(FakeBiometricsService $fake, string $secret = self::SECRET): BiometricsClient
    {
        return new BiometricsClient($fake, 'http://127.0.0.1:8765', $secret);
    }

    public function testSignedRequestAndVerifiedResponse(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $result = $this->client($fake)->analyze(['reference_date' => '2026-09-27']);
        self::assertSame(30, $result->age);
        self::assertSame(0.82, $result->faceMatchScore);
        self::assertTrue($result->livenessPassed && $result->mrzValid);
        self::assertSame(['reference_date' => '2026-09-27'], $fake->lastRequest);
    }

    public function testWrongSecretIsRejectedByTheService(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $fake->responseSecret = 'other-secret-0123456789abcdef-0123456789abc';
        try {
            $this->client($fake, 'wrong-secret-0123456789abcdef-0123456789ab')->analyze([]);
            self::fail('Réponse acceptée malgré un secret différent.');
        } catch (BiometricsException $e) {
            // Le faux service répond 401, signé avec un autre secret que celui du client : refus.
            self::assertSame(BiometricsException::PROTOCOL, $e->reason);
        }
    }

    public function testForgedResponseFromAnotherLocalProcessIsRejected(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $fake->responseSecret = 'attacker-secret-0123456789abcdef-012345678';
        $this->expectExceptionObject(new BiometricsException(BiometricsException::PROTOCOL, 'signature'));
        $this->client($fake)->analyze([]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function outOfContract(): iterable
    {
        yield 'champ en plus (nom)' => [[...FakeBiometricsService::result(), 'name' => 'SPECIMEN']];
        yield 'date de naissance' => [[...FakeBiometricsService::result(), 'birth_date' => '2000-01-01']];
        yield 'champ manquant' => [array_diff_key(FakeBiometricsService::result(), ['reasons' => 1])];
        yield 'âge en chaîne' => [FakeBiometricsService::result(age: null) + ['age' => '30']];
        yield 'score hors bornes' => [FakeBiometricsService::result(score: 1.5)];
        yield 'MRZ valide sans âge' => [FakeBiometricsService::result(age: null)];
        yield 'MRZ invalide avec âge' => [FakeBiometricsService::result(mrz: false)];
        yield 'motif libre' => [FakeBiometricsService::result(reasons: ['Nom: SPECIMEN'])];
        yield 'vivant non booléen' => [[...FakeBiometricsService::result(), 'liveness_passed' => 1]];
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('outOfContract')]
    public function testResponsesOutOfContractAreRejectedWhole(array $response): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $fake->response = $response;
        try {
            $this->client($fake)->analyze([]);
            self::fail('Réponse hors contrat acceptée.');
        } catch (BiometricsException $e) {
            self::assertSame(BiometricsException::PROTOCOL, $e->reason);
            self::assertStringNotContainsString('SPECIMEN', $e->getMessage() . $e->detail);
        }
    }

    public function testErrorStatusesMapToStableCodes(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        foreach ([[503, ['error' => 'busy'], BiometricsException::BUSY], [422, ['error' => 'image_invalid'], BiometricsException::REJECTED], [500, ['error' => 'internal_error'], BiometricsException::UNAVAILABLE]] as [$status, $body, $reason]) {
            $fake->status = $status;
            $fake->response = $body;
            try {
                $this->client($fake)->analyze([]);
                self::fail('Statut ' . $status . ' accepté.');
            } catch (BiometricsException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        $fake->down = true;
        $this->expectExceptionObject(new BiometricsException(BiometricsException::UNAVAILABLE));
        $this->client($fake)->analyze([]);
    }

    public function testNonceIsNeverReused(): void
    {
        $fake = new FakeBiometricsService(self::SECRET);
        $client = $this->client($fake);
        for ($i = 0; $i < 20; $i++) {
            $client->analyze([]); // le faux service refuserait (401) un nonce déjà vu
        }
        self::assertSame(20, $fake->calls);
    }

    public function testServiceMustBeOnTheLoopback(): void
    {
        foreach (['https://127.0.0.1:8765', 'http://10.0.0.5:8765', 'http://localhost:8765', 'http://biometrics.example:8765', 'http://u:p@127.0.0.1:8765', 'http://127.0.0.1.evil:8765'] as $url) {
            try {
                BiometricsClient::assertLoopbackUrl($url);
                self::fail('URL acceptée : ' . $url);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        BiometricsClient::assertLoopbackUrl('http://[::1]:8765');
        $this->expectException(\InvalidArgumentException::class);
        new BiometricsClient(new FakeBiometricsService(self::SECRET), 'http://127.0.0.1:8765', 'court');
    }

    public function testAnalysisResultAcceptsTheContract(): void
    {
        $result = AnalysisResult::fromArray(FakeBiometricsService::result(age: null, expired: null, score: null, liveness: false, mrz: false, reasons: ['mrz_not_found', 'liveness_challenge_failed']));
        self::assertNull($result->age);
        self::assertSame(['mrz_not_found', 'liveness_challenge_failed'], $result->reasons);
        // Score entier (0 ou 1, possible en JSON) lu comme un réel.
        self::assertSame(1.0, AnalysisResult::fromArray([...FakeBiometricsService::result(), 'face_match_score' => 1])->faceMatchScore);
    }
}
