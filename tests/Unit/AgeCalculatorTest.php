<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\Biometrics\AgeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Âge exact : anniversaire le jour même, 29 février, veille de l'anniversaire, expiration incluse. */
final class AgeCalculatorTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function ages(): iterable
    {
        yield 'anniversaire le jour même' => ['2008-09-27', '2026-09-27', 18];
        yield 'veille de l\'anniversaire' => ['2008-09-28', '2026-09-27', 17];
        yield '29 février, 28 février non bissextile' => ['2008-02-29', '2026-02-28', 17];
        yield '29 février, 1er mars non bissextile' => ['2008-02-29', '2026-03-01', 18];
        yield '29 février, année bissextile' => ['2008-02-29', '2028-02-29', 20];
        yield 'né le 1er mars, 29 février bissextile' => ['2010-03-01', '2028-02-29', 17];
        yield '31 décembre' => ['2007-12-31', '2025-12-31', 18];
        yield 'le jour de la naissance' => ['2026-09-27', '2026-09-27', 0];
    }

    #[DataProvider('ages')]
    public function testExactAge(string $birth, string $today, int $age): void
    {
        self::assertSame($age, AgeCalculator::age(new \DateTimeImmutable($birth), new \DateTimeImmutable($today)));
        self::assertSame($age >= 18, AgeCalculator::isAtLeast(new \DateTimeImmutable($birth), new \DateTimeImmutable($today), 18));
    }

    /**
     * Même règle d'âge et d'expiration que le microservice : vecteurs de biometrics/tests/fixtures/mrz_vectors.json
     * (la MRZ elle-même ne sort jamais du service ; seule la règle est partagée, elle resservira à l'eID).
     */
    public function testSameAgeRuleAsTheBiometricsService(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/biometrics/tests/fixtures/mrz_vectors.json'), true, 32, JSON_THROW_ON_ERROR);
        $checked = 0;
        foreach ($data['vectors'] as $vector) {
            $expected = $vector['expected'];
            if (($expected['valid'] ?? false) !== true) {
                continue;
            }
            $today = new \DateTimeImmutable($vector['reference_date']);
            self::assertSame($expected['age'], AgeCalculator::age(new \DateTimeImmutable($expected['birth_date']), $today), $vector['name']);
            self::assertSame($expected['expired'], AgeCalculator::isExpired(new \DateTimeImmutable($expected['expiry_date']), $today), $vector['name']);
            $checked++;
        }
        self::assertGreaterThan(5, $checked);
    }

    public function testBirthInTheFutureIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgeCalculator::age(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2026-09-27'));
    }

    public function testDocumentIsValidThroughItsExpiryDate(): void
    {
        $today = new \DateTimeImmutable('2026-09-27 23:59:59', new \DateTimeZone('Europe/Brussels'));
        self::assertFalse(AgeCalculator::isExpired(new \DateTimeImmutable('2026-09-27'), $today));
        self::assertTrue(AgeCalculator::isExpired(new \DateTimeImmutable('2026-09-26'), $today));
    }
}
