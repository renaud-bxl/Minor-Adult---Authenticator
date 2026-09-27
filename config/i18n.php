<?php

declare(strict_types=1);

// Les 24 langues officielles de l'UE, avec leur autonyme (nom de la langue dans la langue elle-même,
// affiché tel quel dans le sélecteur : ce n'est pas un texte d'interface à traduire).
$languages = [
    'bg' => 'Български', 'cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch',
    'el' => 'Ελληνικά', 'en' => 'English', 'es' => 'Español', 'et' => 'Eesti',
    'fi' => 'Suomi', 'fr' => 'Français', 'ga' => 'Gaeilge', 'hr' => 'Hrvatski',
    'hu' => 'Magyar', 'it' => 'Italiano', 'lt' => 'Lietuvių', 'lv' => 'Latviešu',
    'mt' => 'Malti', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
    'ro' => 'Română', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina', 'sv' => 'Svenska',
];

$enabled = array_values(array_filter(
    array_map('trim', explode(',', strtolower((string) env('LANGS_ENABLED', 'fr,en')))),
    static fn (string $code): bool => isset($languages[$code]),
));

return [
    'languages' => $languages,
    'enabled' => $enabled,
    'fallback' => (string) env('LANG_FALLBACK', 'en'),
    // Langue de référence des traductions (tools/check_translations.php).
    'source' => 'fr',
    'domains' => ['site', 'module', 'emails', 'billing', 'errors'],
    'path' => 'lang',
];
