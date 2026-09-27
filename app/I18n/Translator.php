<?php

declare(strict_types=1);

namespace App\I18n;

use App\Core\Logger;

/**
 * Traductions : clés « domaine.clé » (ex. « site.home.title ») lues dans lang/{code}/{domaine}.php.
 *
 * Chaîne de repli : langue demandée → langue de repli (EN) → la clé elle-même (et un avertissement
 * dans le journal, une seule fois par clé et par requête). Paramètres : « {nom} » dans le texte.
 */
final class Translator
{
    /** @var array<string, array<string, array<string, string>>> [langue][domaine] => clés */
    private array $loaded = [];

    /** @var array<string, true> */
    private array $reportedMissing = [];

    private string $locale;

    /**
     * @param list<string> $enabled langues servies
     * @param list<string> $domains domaines autorisés
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $enabled,
        private readonly array $domains,
        private readonly string $fallback,
        private readonly ?Logger $logger = null,
    ) {
        if (!in_array($fallback, $enabled, true)) {
            throw new \InvalidArgumentException('La langue de repli doit faire partie des langues activées.');
        }
        $this->locale = $fallback;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if (!$this->isEnabled($locale)) {
            throw new \InvalidArgumentException('Langue non activée : ' . $locale);
        }
        $this->locale = $locale;
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled, true);
    }

    /** @return list<string> */
    public function enabled(): array
    {
        return $this->enabled;
    }

    public function fallback(): string
    {
        return $this->fallback;
    }

    /** @param array<string, string|int|float> $params */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        [$domain, $item] = $this->splitKey($key);

        $text = $domain === null ? null : ($this->messages($locale, $domain)[$item] ?? null);
        if ($text === null && $domain !== null && $locale !== $this->fallback) {
            $text = $this->messages($this->fallback, $domain)[$item] ?? null;
        }
        if ($text === null) {
            $this->reportMissing($key, $locale);
            $text = $key;
        }

        if ($params === []) {
            return $text;
        }
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * Toutes les clés d'un domaine pour une langue, complétées par la langue de repli.
     *
     * @return array<string, string>
     */
    public function domain(string $domain, string $locale): array
    {
        if (!in_array($domain, $this->domains, true)) {
            throw new \InvalidArgumentException('Domaine de traduction inconnu : ' . $domain);
        }
        $messages = $this->messages($locale, $domain);
        if ($locale !== $this->fallback) {
            $messages += $this->messages($this->fallback, $domain);
        }
        ksort($messages);

        return $messages;
    }

    /** @return array{0: ?string, 1: string} */
    private function splitKey(string $key): array
    {
        $pos = strpos($key, '.');
        if ($pos === false || !in_array(substr($key, 0, $pos), $this->domains, true)) {
            return [null, $key];
        }

        return [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    /** @return array<string, string> */
    private function messages(string $locale, string $domain): array
    {
        if (!isset($this->loaded[$locale][$domain])) {
            $file = $this->directory . '/' . $locale . '/' . $domain . '.php';
            $messages = preg_match('/^[a-z]{2}$/', $locale) === 1 && is_file($file) ? require $file : [];
            $this->loaded[$locale][$domain] = is_array($messages) ? array_filter($messages, 'is_string') : [];
        }

        return $this->loaded[$locale][$domain];
    }

    private function reportMissing(string $key, string $locale): void
    {
        if (!isset($this->reportedMissing[$key])) {
            $this->reportedMissing[$key] = true;
            $this->logger?->warning('translation_missing', ['key' => $key, 'locale' => $locale]);
        }
    }
}
