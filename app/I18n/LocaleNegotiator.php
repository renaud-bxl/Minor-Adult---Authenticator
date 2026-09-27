<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Détermination de la langue, par ordre de priorité décroissante :
 *   1. paramètre « lang » (choix explicite, mémorisé en session) ;
 *   2. préférence du compte (chargée en session à la connexion) ;
 *   3. préfixe d'URL (/fr/…) ;
 *   4. en-tête Accept-Language (q-values respectées) ;
 *   5. langue de repli (EN).
 * Seules les langues activées sont retenues à chaque étape.
 */
final class LocaleNegotiator
{
    /** @param list<string> $enabled */
    public function __construct(private readonly array $enabled, private readonly string $fallback)
    {
    }

    public function negotiate(
        ?string $queryLang = null,
        ?string $accountLang = null,
        ?string $urlLang = null,
        ?string $acceptLanguage = null,
    ): string {
        foreach ([$queryLang, $accountLang, $urlLang] as $candidate) {
            if ($candidate !== null && $this->isEnabled($candidate)) {
                return $candidate;
            }
        }

        return $this->fromAcceptLanguage($acceptLanguage ?? '') ?? $this->fallback;
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled, true);
    }

    /**
     * Première langue activée de l'en-tête Accept-Language, par q décroissant (ordre d'apparition à
     * q égal). Les sous-étiquettes régionales sont ramenées à la langue (« fr-BE » → « fr ») ; q=0
     * sur une langue (sans région) l'exclut ; « * » désigne la langue de repli.
     */
    public function fromAcceptLanguage(string $header): ?string
    {
        $candidates = [];
        $excluded = [];
        foreach (array_slice(explode(',', $header), 0, 32) as $position => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = strtolower(array_shift($pieces));
            if ($tag === '' || preg_match('/^(?:\*|[a-z]{1,8}(?:-[a-z0-9]{1,8})*)$/', $tag) !== 1) {
                continue;
            }
            $quality = 1.0;
            foreach ($pieces as $piece) {
                if (preg_match('/^q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/i', $piece, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }
            $language = $tag === '*' ? $this->fallback : explode('-', $tag)[0];
            if ($quality <= 0.0) {
                // « fr;q=0 » exclut le français ; « fr-BE;q=0 » n'exclut qu'une variante, pas « fr ».
                if ($tag === $language) {
                    $excluded[$language] = true;
                }
                continue;
            }
            $candidates[] = ['lang' => $language, 'q' => $quality, 'pos' => $position];
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['q'], $a['pos']] <=> [$a['q'], $b['pos']]);
        foreach ($candidates as $candidate) {
            if ($this->isEnabled($candidate['lang']) && !isset($excluded[$candidate['lang']])) {
                return $candidate['lang'];
            }
        }

        return null;
    }
}
