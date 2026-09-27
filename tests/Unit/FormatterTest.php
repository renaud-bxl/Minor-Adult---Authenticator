<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\I18n\Formatter;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Formatter('Europe/Brussels');
    }

    public function testDatesAreLocalizedAndShownInBrusselsTime(): void
    {
        // 23:30 UTC le 31 décembre = 1er janvier à Bruxelles.
        $date = new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('UTC'));
        self::assertSame('1 janvier 2027', $this->formatter->date($date, 'fr'));
        self::assertSame('January 1, 2027', $this->formatter->date($date, 'en'));
    }

    public function testNumbers(): void
    {
        self::assertSame("1\u{202F}234,5", $this->formatter->number(1234.5, 'fr'));
        self::assertSame('1,234.5', $this->formatter->number(1234.5, 'en'));
    }

    public function testCurrencyFromMinorUnits(): void
    {
        self::assertSame("1\u{202F}000,00\u{00A0}€", $this->formatter->currency(100000, 'EUR', 'fr'));
        self::assertSame('€0.60', $this->formatter->currency(60, 'EUR', 'en'));
    }
}
