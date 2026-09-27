<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Âge exact en années révolues, à une date de référence (fuseau de l'application).
 * - l'anniversaire compte le jour même ;
 * - né un 29 février : un an de plus le 1er mars les années non bissextiles (règle prudente, jamais
 *   d'avance sur l'âge légal) ;
 * - une date de naissance dans le futur est une erreur.
 * Même règle que le microservice (biometrics/veriage_biometrics/mrz.py), vérifiée par les mêmes vecteurs.
 * Resservira à l'eID belge (phase 4 : date de naissance déduite du numéro de registre national).
 */
final class AgeCalculator
{
    public static function age(\DateTimeImmutable $birth, \DateTimeImmutable $today): int
    {
        $b = [(int) $birth->format('Y'), (int) $birth->format('n'), (int) $birth->format('j')];
        $t = [(int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j')];
        if ($b > $t) {
            throw new \InvalidArgumentException('Date de naissance dans le futur.');
        }
        $hadBirthday = [$t[1], $t[2]] >= [$b[1], $b[2]];

        return $t[0] - $b[0] - ($hadBirthday ? 0 : 1);
    }

    public static function isAtLeast(\DateTimeImmutable $birth, \DateTimeImmutable $today, int $years): bool
    {
        return self::age($birth, $today) >= $years;
    }

    /** Un document est valable jusqu'à sa date d'expiration incluse. */
    public static function isExpired(\DateTimeImmutable $expiry, \DateTimeImmutable $today): bool
    {
        return $expiry->format('Y-m-d') < $today->format('Y-m-d');
    }
}
