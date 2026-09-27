<?php

declare(strict_types=1);
/**
 * Étape 2, preuve renforcée : un lien à usage unique a été envoyé (trop de codes erronés pour cette
 * adresse, tous sites confondus). La personne clique sur le lien, confirme, puis revient ici.
 *
 * @var string $maskedEmail
 * @var int    $minutes
 * @var string $state
 * @var string $actionBase
 * @var string $query
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'email']) ?>
    <h1 id="verify-title"><?= e(__('module.link.title')) ?></h1>
    <p><?= e(__('module.link.intro', ['email' => $maskedEmail])) ?></p>
    <p class="muted"><?= e(__('module.link.why')) ?></p>
    <p><a class="button button-block" href="<?= e($actionBase . $query) ?>"><?= e(__('module.link.continue')) ?></a></p>

    <form method="post" action="<?= e($actionBase . '/code/resend' . $query) ?>" class="resend-form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <span class="muted"><?= e(__('module.code.not_received')) ?></span>
        <button type="submit" class="link-button link-button-brand"><?= e(__('module.link.resend')) ?></button>
    </form>
</section>
