<?php

declare(strict_types=1);
/**
 * Message simple (lien invalide ou expiré…).
 *
 * @var string $title
 * @var string $message
 * @var string $linkUrl
 * @var string $linkLabel
 */
?>
<section class="auth-card">
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a class="button" href="<?= e($linkUrl) ?>"><?= e($linkLabel) ?></a></p>
</section>
