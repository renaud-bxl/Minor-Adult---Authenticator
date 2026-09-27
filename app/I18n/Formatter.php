<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Formats localisés (extension intl) : dates, nombres et montants. Les dates sont affichées dans
 * le fuseau de l'application (Europe/Brussels), quelle que soit leur zone d'origine (UTC en base).
 */
final class Formatter
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function date(\DateTimeInterface $date, string $locale, int $dateStyle = \IntlDateFormatter::LONG, int $timeStyle = \IntlDateFormatter::NONE): string
    {
        $formatter = new \IntlDateFormatter($locale, $dateStyle, $timeStyle, $this->timezone, \IntlDateFormatter::GREGORIAN);

        return (string) $formatter->format($date);
    }

    public function number(int|float $value, string $locale, int $maxDecimals = 2): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return (string) $formatter->format($value);
    }

    /** @param int $minorUnits montant en centimes (jamais de flottant pour de l'argent) */
    public function currency(int $minorUnits, string $currency, string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($minorUnits / 100, $currency);
    }
}
