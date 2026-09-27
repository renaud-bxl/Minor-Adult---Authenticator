<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\Biometrics\AgeCalculator;
use App\Verification\Biometrics\Mrz;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MRZ (ICAO 9303) côté PHP : chiffres de contrôle 7-3-1, formats TD1/TD2/TD3, dates, âge et expiration.
 * Mêmes vecteurs que le microservice Python (biometrics/tests/fixtures/mrz_vectors.json) : les deux
 * implémentations doivent rendre exactement les mêmes verdicts.
 */
final class MrzTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/biometrics/tests/fixtures/mrz_vectors.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($data['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /** @param array{lines: list<string>, reference_date: string, expected: array<string, mixed>} $vector */
    #[DataProvider('vectors')]
    public function testSharedVectorsGiveTheSameVerdictAsPython(array $vector): void
    {
        $today = new \DateTimeImmutable($vector['reference_date'], new \DateTimeZone('UTC'));
        $expected = $vector['expected'];
        if (isset($expected['error'])) {
            try {
                Mrz::parse($vector['lines'], $today);
                self::fail('Erreur attendue : ' . $expected['error']);
            } catch (\InvalidArgumentException $e) {
                self::assertSame($expected['error'], $e->getMessage());
            }

            return;
        }
        $parsed = Mrz::parse($vector['lines'], $today);
        self::assertSame($expected['format'], $parsed['format']);
        self::assertSame($expected['valid'], $parsed['valid'], json_encode($parsed['checks'], JSON_THROW_ON_ERROR));
        if ($expected['valid']) {
            self::assertSame($expected['birth_date'], $parsed['birth']->format('Y-m-d'));
            self::assertSame($expected['expiry_date'], $parsed['expiry']->format('Y-m-d'));
            self::assertSame($expected['age'], AgeCalculator::age($parsed['birth'], $today));
            self::assertSame($expected['expired'], AgeCalculator::isExpired($parsed['expiry'], $today));
        }
    }

    public function testCheckDigitsOfTheIcaoExamples(): void
    {
        foreach (['D23145890' => '7', '740812' => '2', '120415' => '9', 'L898902C3' => '6', 'ZE184226B<<<<<' => '1', '<<<<<<<<<' => '0'] as $field => $digit) {
            self::assertSame($digit, Mrz::checkDigit((string) $field), (string) $field);
        }
        self::assertTrue(Mrz::checkOk('<<<<<<<<<<<<<<', '<'));
        self::assertFalse(Mrz::checkOk('AB<<<<<<<<<<<<', '<'));
        self::assertFalse(Mrz::checkOk('740812', '3'));
    }

    public function testParsedDataHoldsNoIdentity(): void
    {
        $parsed = Mrz::parse(['I<UTOD231458907<<<<<<<<<<<<<<<', '7408122F1204159UTO<<<<<<<<<<<6', 'ERIKSSON<<ANNA<MARIA<<<<<<<<<<'], new \DateTimeImmutable('2026-09-27'));
        self::assertSame(['format', 'valid', 'checks', 'birth', 'expiry'], array_keys($parsed));
        $dump = var_export($parsed, true);
        foreach (['ERIKSSON', 'ANNA', 'D23145890'] as $identity) {
            self::assertStringNotContainsString($identity, $dump);
        }
    }

    public function testCenturyResolution(): void
    {
        $today = new \DateTimeImmutable('2026-09-27');
        self::assertSame('2026-09-27', Mrz::birthDate('260927', $today)->format('Y-m-d'));
        self::assertSame('1926-09-28', Mrz::birthDate('260928', $today)->format('Y-m-d'));
        self::assertSame('2031-05-20', Mrz::expiryDate('310520', $today)->format('Y-m-d'));
        self::assertSame('1999-01-01', Mrz::expiryDate('990101', $today)->format('Y-m-d'));
        foreach (['001332', '000230', '0A0101', '000000'] as $invalid) {
            try {
                Mrz::birthDate($invalid, $today);
                self::fail('Date invalide acceptée : ' . $invalid);
            } catch (\InvalidArgumentException $e) {
                self::assertSame('mrz_invalid_date', $e->getMessage());
            }
        }
    }
}
