<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Contrôle d'une MRZ (ICAO 9303, formats TD1, TD2 et TD3) : chiffres de contrôle 7-3-1, dates, âge et
 * expiration. Implémentation PHP indépendante de celle du microservice (défense en profondeur), vérifiée
 * par les MÊMES vecteurs (biometrics/tests/fixtures/mrz_vectors.json).
 *
 * Minimisation : ne renvoie que le format, les dates et le résultat des contrôles ; le nom, le numéro
 * du document et les zones facultatives (numéro de registre national belge) ne sont jamais conservés.
 * Le contrat du microservice ne transmet PAS la MRZ à PHP (ni nom, ni numéro, ni date de naissance) :
 * cette classe sert aux tests de parité et, en phase 4, aux documents lus par d'autres moyens.
 *
 * @phpstan-type Parsed array{format: string, valid: bool, checks: array<string, bool>, birth: \DateTimeImmutable, expiry: \DateTimeImmutable}
 */
final class Mrz
{
    private const LENGTHS = ['TD1' => [30, 3], 'TD2' => [36, 2], 'TD3' => [44, 2]];
    private const ACCEPTED = ['TD1' => 'IAC', 'TD2' => 'IAC', 'TD3' => 'P'];
    private const TO_DIGIT = ['O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0', 'I' => '1', 'L' => '1', 'T' => '1',
        'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8'];
    private const TO_ALPHA = ['0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '6' => 'G', '8' => 'B'];
    private const AMBIGUOUS = ['0' => 'O', 'O' => '0', '1' => 'I', 'I' => '1', '8' => 'B', 'B' => '8', '5' => 'S', 'S' => '5', '2' => 'Z', 'Z' => '2'];

    public static function checkDigit(string $field): string
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($field) as $i => $char) {
            $sum += self::value($char) * $weights[$i % 3];
        }

        return (string) ($sum % 10);
    }

    public static function checkOk(string $field, string $digit): bool
    {
        if ($digit === '<') {
            return trim($field, '<') === '';
        }

        return ctype_digit($digit) && self::checkDigit($field) === $digit;
    }

    /**
     * @param list<string> $lines
     * @return Parsed
     *
     * @throws \InvalidArgumentException code stable (mrz_unknown_format, mrz_invalid_character…)
     */
    public static function parse(array $lines, \DateTimeImmutable $today): array
    {
        $lines = array_map(static fn (string $l): string => str_replace(' ', '', strtoupper(trim($l))), $lines);
        foreach ($lines as $line) {
            if (preg_match('/^[A-Z0-9<]*$/D', $line) !== 1) {
                throw new \InvalidArgumentException('mrz_invalid_character');
            }
        }
        $format = self::format($lines);
        $lines = self::normalize($lines, $format);
        if (!str_contains(self::ACCEPTED[$format], $lines[0][0])) {
            throw new \InvalidArgumentException('mrz_unsupported_document');
        }

        $checks = [];
        if ($format === 'TD1') {
            [$l1, $l2] = $lines;
            [$rawNumber, $numberDigit] = self::td1Number($l1);
            $number = self::disambiguate($rawNumber, $numberDigit);
            $n = strlen($number);
            $l1 = substr($l1, 0, 5) . ($n > 9 ? substr($number, 0, 9) . '<' . substr($number, 9) : $number) . substr($l1, 5 + $n + ($n > 9 ? 1 : 0));
            [$birth, $birthDigit, $expiry, $expiryDigit] = [substr($l2, 0, 6), $l2[6], substr($l2, 8, 6), $l2[14]];
            $composite = substr($l1, 5, 25) . substr($l2, 0, 7) . substr($l2, 8, 7) . substr($l2, 18, 11);
            $compositeDigit = $l2[29];
        } else {
            $l2 = $lines[1];
            [$number, $numberDigit] = [self::disambiguate(substr($l2, 0, 9), $l2[9]), $l2[9]];
            [$birth, $birthDigit, $expiry, $expiryDigit] = [substr($l2, 13, 6), $l2[19], substr($l2, 21, 6), $l2[27]];
            if ($format === 'TD2') {
                $composite = $number . $numberDigit . substr($l2, 13, 7) . substr($l2, 21, 14);
                $compositeDigit = $l2[35];
            } else {
                $optional = self::disambiguate(substr($l2, 28, 14), $l2[42]);
                $checks['personal_number'] = self::checkOk($optional, $l2[42]);
                $composite = $number . $numberDigit . substr($l2, 13, 7) . substr($l2, 21, 7) . $optional . $l2[42];
                $compositeDigit = $l2[43];
            }
        }
        $checks = [
            'document_number' => self::checkOk($number, $numberDigit),
            'birth_date' => self::checkOk($birth, $birthDigit),
            'expiry_date' => self::checkOk($expiry, $expiryDigit),
            ...$checks,
            'composite' => self::checkOk($composite, $compositeDigit),
        ];

        return [
            'format' => $format,
            'valid' => !in_array(false, $checks, true),
            'checks' => $checks,
            'birth' => self::birthDate($birth, $today),
            'expiry' => self::expiryDate($expiry, $today),
        ];
    }

    /** Siècle : la date la plus récente qui ne soit pas dans le futur (erreur éventuelle dans le sens prudent). */
    public static function birthDate(string $field, \DateTimeImmutable $today): \DateTimeImmutable
    {
        [$yy, $month, $day] = self::dateParts($field);
        foreach ([2000, 1900] as $century) {
            $date = self::buildDate($century + $yy, $month, $day);
            if ($date->format('Y-m-d') <= $today->format('Y-m-d')) {
                return $date;
            }
        }
        throw new \InvalidArgumentException('mrz_birth_date_in_future');
    }

    public static function expiryDate(string $field, \DateTimeImmutable $today): \DateTimeImmutable
    {
        [$yy, $month, $day] = self::dateParts($field);
        if ($month === null || $day === null) {
            throw new \InvalidArgumentException('mrz_invalid_date');
        }
        $year = 2000 + $yy;
        if ($year > (int) $today->format('Y') + 50) {
            $year -= 100;
        }

        return self::buildDate($year, $month, $day);
    }

    private static function value(string $char): int
    {
        return match (true) {
            ctype_digit($char) => (int) $char,
            $char >= 'A' && $char <= 'Z' => ord($char) - ord('A') + 10,
            $char === '<' => 0,
            default => throw new \InvalidArgumentException('mrz_invalid_character'),
        };
    }

    /** @param list<string> $lines */
    private static function format(array $lines): string
    {
        foreach (self::LENGTHS as $format => [$width, $count]) {
            if (count($lines) === $count && array_filter($lines, static fn (string $l): bool => strlen($l) !== $width) === []) {
                return $format;
            }
        }
        throw new \InvalidArgumentException('mrz_unknown_format');
    }

    /** @return list<string> nature attendue de chaque position : N chiffre, A lettre, X alphanumérique, S sexe, F libre */
    private static function layout(string $format): array
    {
        if ($format === 'TD1') {
            return ['AAAAA' . str_repeat('X', 9) . 'F' . str_repeat('X', 15), str_repeat('N', 7) . 'S' . str_repeat('N', 7) . 'AAA' . str_repeat('X', 11) . 'N', str_repeat('A', 30)];
        }
        $width = self::LENGTHS[$format][0];
        $line2 = str_repeat('X', 9) . 'NAAA' . str_repeat('N', 7) . 'S' . str_repeat('N', 7)
            . ($format === 'TD2' ? str_repeat('X', 7) . 'N' : str_repeat('X', 14) . 'FN');

        return [str_repeat('A', $width), $line2];
    }

    /**
     * Corrige les confusions d'OCR selon la nature attendue de chaque position.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function normalize(array $lines, string $format): array
    {
        $layout = self::layout($format);
        foreach ($lines as $index => $line) {
            $out = '';
            foreach (str_split($line) as $pos => $char) {
                $kind = $layout[$index][$pos];
                $out .= match (true) {
                    $kind === 'N' && $char !== '<' && $char !== 'X' => self::TO_DIGIT[$char] ?? $char,
                    $kind === 'A' && $char !== '<' => self::TO_ALPHA[$char] ?? $char,
                    $kind === 'S' => ['H' => 'M', 'N' => 'M', 'E' => 'F', 'P' => 'F'][$char] ?? $char,
                    default => $char,
                };
            }
            $lines[$index] = $out;
        }

        return $lines;
    }

    /** Champ alphanumérique au contrôle faux : lectures ambiguës essayées (au plus 8 positions). */
    private static function disambiguate(string $field, string $digit): string
    {
        if (self::checkOk($field, $digit)) {
            return $field;
        }
        $positions = array_slice(array_keys(array_filter(str_split($field), static fn (string $c): bool => isset(self::AMBIGUOUS[$c]))), 0, 8);
        $count = count($positions);
        for ($size = 1; $size <= $count; $size++) {
            foreach (self::combinations($positions, $size) as $subset) {
                $candidate = $field;
                foreach ($subset as $i) {
                    $candidate[$i] = self::AMBIGUOUS[$candidate[$i]];
                }
                if (self::checkOk($candidate, $digit)) {
                    return $candidate;
                }
            }
        }

        return $field;
    }

    /**
     * @param list<int> $items
     * @return \Generator<list<int>>
     */
    private static function combinations(array $items, int $size, int $start = 0): \Generator
    {
        if ($size === 0) {
            yield [];

            return;
        }
        for ($i = $start; $i <= count($items) - $size; $i++) {
            foreach (self::combinations($items, $size - 1, $i + 1) as $rest) {
                yield [$items[$i], ...$rest];
            }
        }
    }

    /** @return array{0: string, 1: string} numéro TD1 (débordement éventuel dans la zone facultative) et chiffre */
    private static function td1Number(string $line1): array
    {
        [$number, $digit, $optional] = [substr($line1, 5, 9), $line1[14], substr($line1, 15, 15)];
        if ($digit === '<' && $optional[0] !== '<') {
            $overflow = explode('<', $optional, 2)[0];
            if (strlen($overflow) >= 2) {
                return [$number . substr($overflow, 0, -1), substr($overflow, -1)];
            }
        }

        return [$number, $digit];
    }

    /** @return array{0: int, 1: ?int, 2: ?int} */
    private static function dateParts(string $field): array
    {
        if (strlen($field) !== 6 || !ctype_digit(substr($field, 0, 2))) {
            throw new \InvalidArgumentException('mrz_invalid_date');
        }
        $parts = [];
        foreach ([substr($field, 2, 2), substr($field, 4, 2)] as $chunk) {
            $parts[] = match (true) {
                $chunk === '<<' || $chunk === 'XX' => null,
                ctype_digit($chunk) => (int) $chunk,
                default => throw new \InvalidArgumentException('mrz_invalid_date'),
            };
        }

        return [(int) substr($field, 0, 2), $parts[0], $parts[1]];
    }

    /** Parties inconnues : la date la plus TARDIVE possible (âge le plus bas, prudent). */
    private static function buildDate(int $year, ?int $month, ?int $day): \DateTimeImmutable
    {
        $month ??= 12;
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('mrz_invalid_date');
        }
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->format('t');
        $day ??= $last;
        if ($day < 1 || $day > $last) {
            throw new \InvalidArgumentException('mrz_invalid_date');
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('UTC'));
    }
}

