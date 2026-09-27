<?php

declare(strict_types=1);
/**
 * Étape 2 : contrôle de l'adresse e-mail (code à 6 chiffres).
 *
 * @var string      $maskedEmail
 * @var string|null $sandboxCode
 * @var bool        $livemode
 * @var int         $minutes
 * @var string      $state
 * @var string      $actionBase
 * @var string      $query
 * @var array{0: string, 1: string}|null $fieldError erreur de saisie reliée au champ
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'email']) ?>
    <h1 id="verify-title"><?= e(__('module.code.title')) ?></h1>
    <p><?= e(__($livemode ? 'module.code.intro' : 'module.code.intro_sandbox', ['email' => $maskedEmail])) ?> <?= e(__('module.code.expiry', ['minutes' => $minutes])) ?></p>

<?php if (!$livemode): ?>
    <p class="sandbox-code" role="note">
<?php if ($sandboxCode !== null): ?>
        <?= e(__('module.code.sandbox')) ?> <strong data-sandbox-code><?= e($sandboxCode) ?></strong>
<?php else: ?>
        <?= e(__('module.code.sandbox_expired')) ?>
<?php endif; ?>
    </p>
<?php endif; ?>

    <form method="post" action="<?= e($actionBase . '/code' . $query) ?>" class="form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <div class="field">
            <label for="code"><?= e(__('module.code.label')) ?></label>
            <input id="code" name="code" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus
                   aria-describedby="<?= !empty($fieldError) ? 'code-error ' : '' ?>code-hint"<?= !empty($fieldError) ? ' aria-invalid="true"' : '' ?>>
<?php if (!empty($fieldError)): ?>
            <p class="field-error" id="code-error" role="alert"><?= e(__($fieldError[0])) ?></p>
<?php endif; ?>
            <p class="field-hint" id="code-hint"><?= e(__('module.code.hint')) ?></p>
        </div>
        <button type="submit" class="button button-block"><?= e(__('module.code.submit')) ?></button>
    </form>

    <form method="post" action="<?= e($actionBase . '/code/resend' . $query) ?>" class="resend-form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <span class="muted"><?= e(__('module.code.not_received')) ?></span>
        <button type="submit" class="link-button link-button-brand"><?= e(__('module.code.resend')) ?></button>
    </form>
</section>
