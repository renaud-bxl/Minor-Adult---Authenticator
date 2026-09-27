<?php

declare(strict_types=1);
/**
 * Layout du site.
 *
 * @var string      $content
 * @var string|null $pageTitle
 */
$appName = (string) config('app.name');
$isAuthenticated = $isAuthenticated ?? false;
$currentPath = $currentPath ?? '/';
$languages = (array) config('i18n.languages');
$enabledLanguages = (array) config('i18n.enabled');
$flashes = array_filter([
    'success' => $flashSuccess ?? null,
    'error' => $flashError ?? null,
    'info' => $flashInfo ?? null,
]);
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $appName : $appName) ?></title>
    <meta name="description" content="<?= e(__('site.meta.description')) ?>">
<?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($alternates ?? [] as $hreflang => $href): ?>
    <link rel="alternate" hreflang="<?= e($hreflang) ?>" href="<?= e($href) ?>">
<?php endforeach; ?>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main"><?= e(__('site.nav.skip_to_content')) ?></a>

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                <path d="M16 2 4 7v8c0 7.3 5.1 13.3 12 15 6.9-1.7 12-7.7 12-15V7L16 2Z" fill="currentColor"/>
                <path d="m11 16.5 3.5 3.5 7-8" fill="none" stroke="var(--brand-contrast)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?= e($appName) ?></span>
        </a>

        <nav class="main-nav" aria-label="<?= e(__('site.nav.label')) ?>">
            <ul>
<?php if ($isAuthenticated): ?>
                <li><a href="<?= e(url('/dashboard')) ?>"><?= e(__('site.nav.dashboard')) ?></a></li>
                <li>
                    <form method="post" action="<?= e(url('/logout')) ?>" class="inline-form">
                        <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">
                        <button type="submit" class="link-button"><?= e(__('site.nav.logout')) ?></button>
                    </form>
                </li>
<?php else: ?>
                <li><a href="<?= e(url('/login')) ?>"><?= e(__('site.nav.login')) ?></a></li>
                <li><a class="button button-small" href="<?= e(url('/register')) ?>"><?= e(__('site.nav.register')) ?></a></li>
<?php endif; ?>
            </ul>
        </nav>

        <details class="lang-switcher">
            <summary aria-label="<?= e(__('site.nav.language')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9m0-18C9.5 5.6 8.2 8.6 8.2 12s1.3 6.4 3.8 9M3.5 9h17M3.5 15h17"/></svg>
                <span><?= e(strtoupper(locale())) ?></span>
            </summary>
            <ul>
<?php foreach ($enabledLanguages as $code): ?>
                <li>
                    <a href="<?= e(url($currentPath, $code, ['lang' => $code])) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === locale() ? ' aria-current="true"' : '' ?>><?= e($languages[$code] ?? $code) ?></a>
                </li>
<?php endforeach; ?>
            </ul>
        </details>
    </div>
</header>

<main id="main" class="container main">
<?php foreach ($flashes as $type => $key): ?>
    <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <p><?= e(__((string) $key)) ?></p>
        <button type="button" class="flash-close" data-dismiss hidden aria-label="<?= e(__('site.common.close')) ?>">×</button>
    </div>
<?php endforeach; ?>
<?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p>© <?= e(date('Y')) ?> <?= e($appName) ?> · <?= e(__('site.footer.tagline')) ?></p>
        <p class="footer-note"><?= e(__('site.footer.privacy_note')) ?></p>
    </div>
</footer>
</body>
</html>
