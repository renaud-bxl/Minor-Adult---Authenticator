<?php

declare(strict_types=1);
/**
 * Confirmation du lien à usage unique (POST : les scanners de liens ne le consomment pas).
 *
 * @var string $token
 * @var string $state
 * @var string $actionBase
 * @var string $query
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'email']) ?>
    <h1 id="verify-title"><?= e(__('module.link.confirm_title')) ?></h1>
    <p><?= e(__('module.link.confirm_intro')) ?></p>
    <form method="post" action="<?= e($actionBase . '/code' . $query) ?>" class="form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <input type="hidden" name="code" value="<?= e($token) ?>">
        <button type="submit" class="button button-block"><?= e(__('module.link.confirm_submit')) ?></button>
    </form>
</section>
