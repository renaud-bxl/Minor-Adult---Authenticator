<?php

declare(strict_types=1);
/**
 * @var string                $token
 * @var array<string, string> $errors
 * @var int                   $passwordMin
 * @var int                   $passwordMax
 * @var \App\Core\Csrf        $csrf
 */
$field = static fn (string $name): string => $errors[$name] ?? '';
?>
<section class="auth-card">
    <h1><?= e(__('site.reset.title')) ?></h1>
    <p class="muted"><?= e(__('site.reset.intro')) ?></p>

<?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><p><?= e(__('site.validation.summary')) ?></p></div>
<?php endif; ?>

    <form method="post" action="<?= e(url('/reset-password')) ?>" class="form" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field">
            <label for="password"><?= e(__('site.reset.new_password')) ?></label>
            <div class="password-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" required
                       minlength="<?= e($passwordMin) ?>" maxlength="<?= e($passwordMax) ?>"
                       aria-describedby="password-hint<?= $field('password') !== '' ? ' password-error' : '' ?>"<?= $field('password') !== '' ? ' aria-invalid="true"' : '' ?>>
                <button type="button" class="password-toggle" data-password-toggle="password" hidden
                        data-label-show="<?= e(__('site.form.show_password')) ?>" data-label-hide="<?= e(__('site.form.hide_password')) ?>"><?= e(__('site.form.show_password')) ?></button>
            </div>
            <p class="field-hint" id="password-hint"><?= e(__('site.register.password_hint', ['min' => $passwordMin])) ?></p>
<?php if ($field('password') !== ''): ?>
            <p class="field-error" id="password-error"><?= e($field('password')) ?></p>
<?php endif; ?>
        </div>

        <div class="field">
            <label for="password_confirmation"><?= e(__('site.form.password_confirmation')) ?></label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                   maxlength="<?= e($passwordMax) ?>"<?= $field('password_confirmation') !== '' ? ' aria-invalid="true" aria-describedby="password_confirmation-error"' : '' ?>>
<?php if ($field('password_confirmation') !== ''): ?>
            <p class="field-error" id="password_confirmation-error"><?= e($field('password_confirmation')) ?></p>
<?php endif; ?>
        </div>

        <button type="submit" class="button button-block"><?= e(__('site.reset.submit')) ?></button>
    </form>
</section>
