<?php

declare(strict_types=1);

use App\Core\Application;

/*
 * Helpers globaux, volontairement peu nombreux : ils servent surtout aux vues et aux fichiers de config.
 */

if (!function_exists('env')) {
    /** Variable d'environnement typée (« true », « false », « null » et « empty » sont convertis). */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Application::current()->config->get($key, $default);
    }
}

if (!function_exists('__')) {
    /**
     * Traduction d'une clé « domaine.clé » dans la langue courante.
     *
     * @param array<string, string|int|float> $params remplacements « {nom} »
     */
    function __(string $key, array $params = [], ?string $locale = null): string
    {
        return Application::current()->translator()->get($key, $params, $locale);
    }
}

if (!function_exists('e')) {
    /** Échappement HTML (contenu et attributs). */
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('locale')) {
    function locale(): string
    {
        return Application::current()->translator()->locale();
    }
}

if (!function_exists('url')) {
    /**
     * Chemin du site préfixé par la langue : url('/login') → « /fr/login », url('/') → « /fr/ ».
     *
     * @param array<string, string> $query
     */
    function url(string $path = '/', ?string $lang = null, array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        $url = '/' . ($lang ?? locale()) . ($path === '/' ? '/' : $path);

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('absolute_url')) {
    /** URL absolue à partir d'un chemin, basée sur APP_URL (jamais sur l'en-tête Host). */
    function absolute_url(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL d'une ressource statique de public/assets, avec empreinte de version pour le cache. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = Application::current()->basePath . '/public/assets/' . $path;
        $version = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';

        return '/assets/' . $path . '?v=' . $version;
    }
}

if (!function_exists('partial')) {
    /**
     * Rendu d'un gabarit partiel (sans layout), avec les données partagées de la vue.
     *
     * @param array<string, mixed> $data
     */
    function partial(string $template, array $data = []): string
    {
        return Application::current()->view()->render($template, $data, null);
    }
}
