<?php

declare(strict_types=1);
/**
 * Layout de la démonstration : site d'une plateforme cliente FICTIVE, volontairement différent du
 * site VeriAge (autre hôte, autre charte), pour montrer une intégration réelle du widget.
 *
 * @var string      $content
 * @var string|null $pageTitle
 */
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? '') . ' · ' . __('site.demo.shop_name')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= e(asset('css/demo.css')) ?>">
    <script src="<?= e(asset('js/demo.js')) ?>" defer></script>
</head>
<body>
<p class="demo-ribbon" role="note"><?= e(__('site.demo.ribbon')) ?></p>
<header class="shop-header">
    <div class="shop-inner">
        <a class="shop-brand" href="/demo?lang=<?= e(locale()) ?>"><span aria-hidden="true">🍷</span> <?= e(__('site.demo.shop_name')) ?></a>
        <nav class="shop-langs" aria-label="<?= e(__('site.nav.language')) ?>">
<?php foreach ((array) config('i18n.enabled') as $code): ?>
            <a href="/demo?lang=<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === locale() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
<?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="shop-main">
<?= $content ?>
</main>
</body>
</html>
