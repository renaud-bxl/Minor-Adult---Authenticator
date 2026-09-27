<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;

/**
 * Règles de mot de passe (NIST SP 800-63B §5.1.1.2, OWASP ASVS V2.1) :
 * - longueur minimale et borne haute (sans règle de composition arbitraire) ;
 * - UTF-8 valide ;
 * - refus des suites et répétitions triviales (« aaaaaaaaaaaa », « 123456789012 », « azertyuiopqs »…) ;
 * - refus des mots de passe courants ou compromis (liste locale), y compris un mot courant simplement
 *   entouré de chiffres ou de symboles (« Password1234! ») ;
 * - refus des mots de passe liés au contexte : nom du service, adresse e-mail, raison sociale.
 */
final class PasswordPolicy
{
    /** Suites clavier et alphanumériques (FR, BE, DE, US), lues aussi à l'envers. */
    private const SEQUENCES = [
        '0123456789', 'abcdefghijklmnopqrstuvwxyz',
        'qwertyuiopasdfghjklzxcvbnm', 'azertyuiopqsdfghjklmwxcvbn', 'qwertzuiopasdfghjklyxcvbnm',
        '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p', '&é"\'(§è!çà)-',
    ];
    /** Nombre minimal de caractères distincts. */
    private const MIN_DISTINCT = 5;
    /** Longueur minimale d'un élément de contexte pour être recherché dans le mot de passe. */
    private const MIN_CONTEXT_LENGTH = 4;

    /** @param list<string> $serviceWords noms du service toujours interdits (ex. « VeriAge ») */
    public function __construct(
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly PasswordBlocklist $blocklist,
        private readonly array $serviceWords = [],
    ) {
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    public function maxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * @param list<string> $context données de l'utilisateur à exclure (e-mail, raison sociale…)
     * @return array{0: string, 1: array<string, int>}|null clé de traduction et paramètres de l'erreur, ou null si valide
     */
    public function validate(string $password, array $context = []): ?array
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            return ['site.validation.text_invalid', []];
        }
        $length = mb_strlen($password, 'UTF-8');
        if ($length < $this->minLength) {
            return ['site.validation.password_too_short', ['min' => $this->minLength]];
        }
        if ($length > $this->maxLength) {
            return ['site.validation.password_too_long', ['max' => $this->maxLength]];
        }

        $lower = mb_strtolower(\Normalizer::normalize($password, \Normalizer::FORM_C) ?: $password, 'UTF-8');
        if ($this->isTrivial($lower)) {
            return ['site.validation.password_trivial', []];
        }
        if ($this->isCommon($lower)) {
            return ['site.validation.password_common', []];
        }
        if ($this->isContextual($lower, $context)) {
            return ['site.validation.password_contextual', []];
        }

        return null;
    }

    private function isTrivial(string $lower): bool
    {
        $characters = mb_str_split($lower, 1, 'UTF-8');
        if (count(array_unique($characters)) < self::MIN_DISTINCT) {
            return true;
        }
        // Motif répété (« abcabcabcabc », « 12341234… »).
        if (preg_match('/^(.+?)\1+$/su', $lower) === 1) {
            return true;
        }
        foreach (self::SEQUENCES as $sequence) {
            $loop = str_repeat($sequence, (int) ceil(2 * mb_strlen($lower, 'UTF-8') / max(1, mb_strlen($sequence, 'UTF-8'))) + 2);
            if (str_contains($loop, $lower) || str_contains($loop, self::reverse($lower))) {
                return true;
            }
        }

        return false;
    }

    private function isCommon(string $lower): bool
    {
        if ($this->blocklist->contains($lower)) {
            return true;
        }
        // Mot courant décoré : chiffres ou symboles ajoutés au début ou à la fin (« !Soleil2026 »).
        $core = (string) preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $lower);

        return $core !== $lower && mb_strlen($core, 'UTF-8') >= self::MIN_CONTEXT_LENGTH && $this->blocklist->contains($core);
    }

    /** @param list<string> $context */
    private function isContextual(string $lower, array $context): bool
    {
        $compact = self::compact($lower);
        foreach ($this->contextTokens($context) as $token) {
            if (str_contains($compact, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Éléments recherchés : noms du service, partie locale et nom de domaine de l'e-mail, raison
     * sociale complète et chacun de ses mots, sous forme compacte (minuscules, lettres et chiffres).
     *
     * @param list<string> $context
     * @return list<string>
     */
    private function contextTokens(array $context): array
    {
        $raw = $this->serviceWords;
        foreach ($context as $value) {
            $value = Crypto::normalizeEmail($value);
            $raw[] = $value;
            if (str_contains($value, '@')) {
                [$local, $domain] = explode('@', $value, 2);
                $raw[] = $local;
                $raw[] = explode('.', $domain)[0];
            } else {
                array_push($raw, ...(preg_split('/[^\p{L}\p{N}]+/u', $value) ?: []));
            }
        }

        $tokens = [];
        foreach ($raw as $value) {
            $token = self::compact(mb_strtolower($value, 'UTF-8'));
            if (mb_strlen($token, 'UTF-8') >= self::MIN_CONTEXT_LENGTH) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    private static function compact(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    private static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value, 1, 'UTF-8')));
    }
}
