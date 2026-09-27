<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validation et normalisation des champs texte libres (raison sociale, e-mail…), avant tout
 * traitement ou stockage :
 * - UTF-8 valide exigé (sinon la base refuserait la valeur plus tard, après une réponse de succès) ;
 * - normalisation NFC (une même chaîne visuelle = une seule représentation) ;
 * - refus des caractères de contrôle (Cc : sauts de ligne, tabulations, NUL…), de mise en forme
 *   (Cf : inversion bidirectionnelle U+202E, caractères invisibles…) et des séparateurs de ligne ou
 *   de paragraphe (Zl, Zp), qui permettent l'usurpation visuelle ou l'injection dans des formats texte ;
 * - espaces de bord retirés et espaces internes compactés.
 */
final class TextInput
{
    /** @return string|null valeur normalisée, ou null si elle contient des données interdites */
    public static function normalize(string $value): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (!is_string($normalized) || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $normalized) === 1) {
            return null;
        }

        return trim((string) preg_replace('/\p{Zs}+/u', ' ', $normalized));
    }

    /** Version affichable d'une saisie refusée (octets invalides remplacés), pour la réafficher. */
    public static function forDisplay(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
