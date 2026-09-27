<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\I18n\LocaleNegotiator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleNegotiatorTest extends TestCase
{
    private LocaleNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new LocaleNegotiator(['fr', 'en', 'nl'], 'en');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function acceptLanguageProvider(): iterable
    {
        yield 'simple' => ['fr', 'fr'];
        yield 'sous-étiquette régionale' => ['fr-BE', 'fr'];
        yield 'ordre par q' => ['de;q=1, en;q=0.5, fr;q=0.8', 'fr'];
        yield 'ordre d\'apparition à q égal' => ['nl-BE, fr-BE', 'nl'];
        yield 'q=0 exclut la langue' => ['fr;q=0, nl;q=0.2', 'nl'];
        yield 'q=0 sur une variante n\'exclut pas la langue' => ['fr-BE;q=0, fr;q=0.9, en;q=0.1', 'fr'];
        yield 'q=0 sur la langue l\'exclut aussi en variante' => ['fr;q=0, fr-BE, en;q=0.1', 'en'];
        yield 'joker' => ['de, *;q=0.1', 'en'];
        yield 'aucune langue activée' => ['de-DE, ja', null];
        yield 'casse et espaces' => ['  FR-be ; Q=0.7 ,  DE ', 'fr'];
        yield 'q invalide ignoré (vaut 1)' => ['nl;q=abc, fr;q=0.9', 'nl'];
        yield 'étiquette invalide' => ['<script>, fr', 'fr'];
        yield 'vide' => ['', null];
    }

    #[DataProvider('acceptLanguageProvider')]
    public function testParsesAcceptLanguage(string $header, ?string $expected): void
    {
        self::assertSame($expected, $this->negotiator->fromAcceptLanguage($header));
    }

    public function testPriorityOrder(): void
    {
        $n = $this->negotiator;
        self::assertSame('nl', $n->negotiate('nl', 'fr', 'en', 'fr'), 'paramètre lang en premier');
        self::assertSame('fr', $n->negotiate(null, 'fr', 'en', 'nl'), 'puis préférence du compte');
        self::assertSame('en', $n->negotiate(null, null, 'en', 'nl'), 'puis préfixe d\'URL');
        self::assertSame('nl', $n->negotiate(null, null, null, 'nl, fr'), 'puis Accept-Language');
        self::assertSame('en', $n->negotiate(), 'enfin la langue de repli');
    }

    public function testDisabledCandidatesAreSkipped(): void
    {
        self::assertSame('fr', $this->negotiator->negotiate('de', 'xx', 'fr', 'nl'));
    }
}
