<?php

declare(strict_types=1);
/**
 * Page d'erreur générique (404, 405, 419, 429, 500…).
 *
 * @var int         $status
 * @var string      $title
 * @var string      $message
 * @var string|null $debug  détail technique, uniquement hors production avec APP_DEBUG
 */
?>
<section class="error-page">
    <p class="error-code"><?= e($status) ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
<?php if ($debug !== null): ?>
    <pre class="debug"><?= e($debug) ?></pre>
<?php endif; ?>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.common.back_home')) ?></a></p>
</section>
