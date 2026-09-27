<?php

declare(strict_types=1);

/*
 * Contrôle de couverture des traductions.
 *
 * Référence : la langue source (FR). Pour chaque langue contrôlée et chaque domaine :
 *   - clés manquantes (absentes ou vides), clés orphelines (absentes de la référence) ;
 *   - paramètres « {nom} » différents de la référence.
 * Puis scan du code (app/, public/assets/js/) : toute clé « domaine.groupe.clé » citée dans le code
 * doit exister dans la référence. Les clés de la référence jamais citées sont signalées (information).
 *
 * Usage : php tools/check_translations.php [--all | --lang=fr,en]
 *   Par défaut : les langues de LANGS_ENABLED. --all : les 24 langues (rapport informatif).
 * Code retour : 0 si tout est à 100 % sans erreur, 1 sinon.
 */

use App\Core\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::boot(dirname(__DIR__));
$config = $app->config;
$langPath = $app->path((string) $config->get('i18n.path'));
$source = (string) $config->get('i18n.source');
/** @var list<string> $domains */
$domains = $config->get('i18n.domains');
/** @var array<string, string> $allLanguages */
$allLanguages = $config->get('i18n.languages');

$options = getopt('', ['all', 'lang:']);
$languages = match (true) {
    isset($options['all']) => array_keys($allLanguages),
    isset($options['lang']) => array_values(array_filter(array_map('trim', explode(',', (string) $options['lang'])))),
    default => (array) $config->get('i18n.enabled'),
};

/** @return array<string, string> */
$load = static function (string $lang, string $domain) use ($langPath): array {
    $file = $langPath . '/' . $lang . '/' . $domain . '.php';
    if (!is_file($file)) {
        return [];
    }
    $messages = require $file;

    return is_array($messages) ? $messages : [];
};

/** @return list<string> */
$placeholders = static function (string $text): array {
    preg_match_all('/\{([a-z_][a-z0-9_]*)\}/i', $text, $m);
    $names = array_unique($m[1]);
    sort($names);

    return $names;
};

$failed = false;

// 1. Référence.
$reference = [];
foreach ($domains as $domain) {
    if (!is_file($langPath . '/' . $source . '/' . $domain . '.php')) {
        fwrite(STDERR, "ERREUR : fichier de référence manquant : lang/{$source}/{$domain}.php\n");
        $failed = true;
    }
    foreach ($load($source, $domain) as $key => $text) {
        $reference[$domain . '.' . $key] = (string) $text;
    }
}
$total = count($reference);
printf("Référence : %s (%d clés, %d domaines)\n\n", $source, $total, count($domains));

// 2. Couverture par langue.
foreach ($languages as $lang) {
    if (!isset($allLanguages[$lang])) {
        fwrite(STDERR, "ERREUR : langue inconnue : {$lang}\n");
        $failed = true;
        continue;
    }
    $messages = [];
    foreach ($domains as $domain) {
        foreach ($load($lang, $domain) as $key => $text) {
            $messages[$domain . '.' . $key] = $text;
        }
    }

    $missing = [];
    $mismatch = [];
    foreach ($reference as $key => $text) {
        $translated = $messages[$key] ?? null;
        if (!is_string($translated) || trim($translated) === '') {
            $missing[] = $key;
        } elseif ($placeholders($translated) !== $placeholders($text)) {
            $mismatch[] = $key;
        }
    }
    $orphans = array_keys(array_diff_key($messages, $reference));
    $covered = $total - count($missing) - count($mismatch);
    $percent = $total === 0 ? 100.0 : 100 * $covered / $total;

    printf("%-3s %6.1f %%  (%d/%d)", $lang, floor($percent * 10) / 10, $covered, $total);
    $ok = $missing === [] && $mismatch === [] && $orphans === [];
    echo $ok ? "  OK\n" : "\n";
    $details = ['manquante' => $missing, 'paramètres différents' => $mismatch, 'orpheline' => $orphans];
    foreach ($details as $label => $keys) {
        foreach (count($keys) > 20 ? [...array_slice($keys, 0, 20), '… (' . (count($keys) - 20) . ' autres)'] : $keys as $key) {
            echo "      {$label} : {$key}\n";
        }
    }
    $failed = $failed || !$ok;
}

// 3. Scan du code.
$pattern = '/([\'"])((?:' . implode('|', array_map('preg_quote', $domains)) . ')(?:\.[a-z0-9_]+){2,})\1/';
$used = [];
$directories = [$app->basePath . '/app', $app->basePath . '/public/assets/js'];
foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!in_array($file->getExtension(), ['php', 'js'], true)) {
            continue;
        }
        $relative = substr((string) $file->getPathname(), strlen($app->basePath) + 1);
        preg_match_all($pattern, (string) file_get_contents((string) $file->getPathname()), $m);
        foreach ($m[2] as $key) {
            $used[$key][] = $relative;
        }
    }
}
$unknown = array_diff_key($used, $reference);
printf("\nScan du code : %d clés citées, %d inconnue(s).\n", count($used), count($unknown));
foreach ($unknown as $key => $files) {
    echo "      inconnue : {$key} (" . implode(', ', array_unique($files)) . ")\n";
}
$failed = $failed || $unknown !== [];

// Clés jamais citées dans le code (information ; « module » et « billing » sont consommés hors PHP).
$unused = array_filter(
    array_keys(array_diff_key($reference, $used)),
    static fn (string $key): bool => !str_starts_with($key, 'module.') && !str_starts_with($key, 'billing.'),
);
if ($unused !== []) {
    printf("Information : %d clé(s) de référence jamais citée(s) dans le code :\n", count($unused));
    foreach ($unused as $key) {
        echo "      {$key}\n";
    }
}

echo $failed ? "\nÉCHEC : couverture incomplète ou clés invalides.\n" : "\nSUCCÈS : 100 % pour " . implode(', ', $languages) . ".\n";
exit($failed ? 1 : 0);
