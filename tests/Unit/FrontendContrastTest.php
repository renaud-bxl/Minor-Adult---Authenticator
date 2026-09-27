<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.1 critère 1.4.11 : les jetons de couleur des contours de champs et de l'indicateur de
 * focus doivent contraster à au moins 3:1 avec les fonds adjacents, en thème clair et sombre.
 */
final class FrontendContrastTest extends TestCase
{
    public function testNonTextContrastInBothThemes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        $darkStart = (int) strpos($css, '@media (prefers-color-scheme: dark)');
        $light = self::tokens(substr($css, 0, $darkStart));
        $dark = [...$light, ...self::tokens(substr($css, $darkStart, (int) strpos($css, '}', (int) strpos($css, ':root', $darkStart)) - $darkStart))];

        foreach (['clair' => $light, 'sombre' => $dark] as $theme => $t) {
            foreach (['surface', 'bg'] as $background) {
                self::assertGreaterThanOrEqual(3.0, self::ratio($t['field-border'], $t[$background]), "bordure de champ / {$background} ({$theme})");
            }
            foreach (['surface', 'bg', 'surface-muted'] as $background) {
                self::assertGreaterThanOrEqual(3.0, self::ratio($t['focus'], $t[$background]), "focus / {$background} ({$theme})");
            }
        }
    }

    /** @return array<string, string> */
    private static function tokens(string $css): array
    {
        preg_match_all('/--([a-z-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $m);

        return array_combine($m[1], $m[2]);
    }

    private static function ratio(string $a, string $b): float
    {
        [$la, $lb] = [self::luminance($a), self::luminance($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
