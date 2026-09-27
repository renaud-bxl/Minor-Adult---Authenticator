<?php

declare(strict_types=1);
/**
 * Layout du module de vérification (page hébergée, démonstration, erreurs de l'hôte verify.).
 * Aucune navigation du site : la page peut être affichée dans une iframe, une modale ou un popup.
 *
 * @var string                                     $content
 * @var string|null                                $pageTitle
 * @var string|null                                $stepTitle  étape courante (titre propre à chaque page, WCAG 2.4.2)
 * @var \App\Models\Project|null                   $project
 * @var array{mode: ?string, origin: ?string}|null $embed
 * @var bool|null                                  $livemode
 * @var array<string, string>|null                 $languageLinks
 * @var array{0: string, 1: string}|null           $notice
 */
$appName = (string) config('app.name');
$languages = (array) config('i18n.languages');
$project = $project ?? null;
$embed = $embed ?? ['mode' => null, 'origin' => null];
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(implode(' · ', array_filter([$stepTitle ?? null, $pageTitle ?? null, $appName]))) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/verify-page.js')) ?>" defer></script>
</head>
<body class="verify-body"<?= $embed['mode'] !== null ? ' data-embed="' . e($embed['mode']) . '"' : '' ?><?= $embed['origin'] !== null ? ' data-parent-origin="' . e($embed['origin']) . '"' : '' ?><?= isset($session) ? ' data-session="' . e($session->publicId) . '"' : '' ?>>
<div class="verify-shell">
    <header class="verify-header">
        <div class="verify-brand">
            <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                <path d="M16 2 4 7v8c0 7.3 5.1 13.3 12 15 6.9-1.7 12-7.7 12-15V7L16 2Z" fill="currentColor"/>
                <path d="m11 16.5 3.5 3.5 7-8" fill="none" stroke="var(--brand-contrast)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="verify-brand-text">
                <strong><?= e($appName) ?></strong>
<?php if ($project !== null): ?>
                <span class="muted"><?= e(__('module.page.for_project', ['project' => $project->name])) ?></span>
<?php endif; ?>
            </span>
        </div>
<?php if (!empty($languageLinks) && count($languageLinks) > 1): ?>
        <nav class="verify-langs" aria-label="<?= e(__('module.page.language')) ?>">
<?php foreach ($languageLinks as $code => $href): ?>
            <a href="<?= e($href) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>" title="<?= e($languages[$code] ?? $code) ?>"<?= $code === locale() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach; ?>
        </nav>
<?php endif; ?>
<?php if ($embed['mode'] !== null): ?>
        <button type="button" class="verify-close" data-veriage-close hidden aria-label="<?= e(__('module.page.close')) ?>">×</button>
<?php endif; ?>
    </header>

    <main id="main" class="verify-main">
<?php if (isset($livemode) && $livemode === false): ?>
        <p class="sandbox-banner" role="note"><?= e(__('module.page.sandbox')) ?></p>
<?php endif; ?>
<?php if (!empty($notice)): ?>
        <div class="flash flash-<?= e($notice[1]) ?>" role="<?= $notice[1] === 'error' ? 'alert' : 'status' ?>"><p><?= e(__($notice[0])) ?></p></div>
<?php endif; ?>
<?= $content ?>
    </main>

    <footer class="verify-footer">
        <p><?= e($project !== null ? __('module.page.footer', ['app' => $appName, 'project' => $project->name]) : __('module.page.footer_generic', ['app' => $appName])) ?></p>
    </footer>
</div>
</body>
</html>
