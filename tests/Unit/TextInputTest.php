<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\TextInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextInputTest extends TestCase
{
    public function testNormalizesToNfcAndCompactsSpaces(): void
    {
        self::assertSame("Caf\u{00E9} du Parc", TextInput::normalize("  Cafe\u{0301}   du\u{00A0}Parc "));
        self::assertSame('Brasserie Test SRL', TextInput::normalize('Brasserie Test SRL'));
        self::assertSame('Ελληνική Εταιρεία', TextInput::normalize('Ελληνική Εταιρεία'));
        self::assertSame('', TextInput::normalize('   '));
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenProvider(): iterable
    {
        yield 'UTF-8 invalide' => ["Bad\xFF\xFECo"];
        yield 'séquence tronquée' => ["Caf\xC3"];
        yield 'inversion bidi U+202E' => ["\u{202E}evil"];
        yield 'isolat bidi U+2066' => ["a\u{2066}b"];
        yield 'espace de largeur nulle' => ["Ac\u{200B}me"];
        yield 'contrôle SOH' => ["Acme\x01"];
        yield 'cloche' => ["Acme\x07"];
        yield 'NUL' => ["Acme\0SA"];
        yield 'saut de ligne' => ["Acme\nSA"];
        yield 'retour chariot' => ["Acme\r\nBcc: x"];
        yield 'tabulation' => ["Acme\tSA"];
        yield 'séparateur de ligne U+2028' => ["Acme\u{2028}SA"];
        yield 'séparateur de paragraphe U+2029' => ["Acme\u{2029}SA"];
    }

    #[DataProvider('forbiddenProvider')]
    public function testRejectsForbiddenInput(string $value): void
    {
        self::assertNull(TextInput::normalize($value));
    }

    public function testDisplayVersionIsValidUtf8(): void
    {
        self::assertTrue(mb_check_encoding(TextInput::forDisplay("Bad\xFF\xFECo"), 'UTF-8'));
    }
}
