<?php

declare(strict_types=1);
/**
 * @var array{company?: string, email?: string} $old
 * @var array<string, string>                   $errors
 * @var int                                     $passwordMin
 * @var int                                     $passwordMax
 * @var \App\Core\Csrf                          $csrf
 */
$field = static fn (string $name): string => $errors[$name] ?? '';
?>
<section class="auth-card">
    <h1><?= e(__('site.register.title')) ?></h1>
    <p class="muted"><?= e(__('site.register.intro')) ?></p>

<?php if (isset($errors['form'])): ?>
    <div class="flash flash-error" role="alert"><p><?= e($errors['form']) ?></p></div>
<?php elseif ($errors !== []): ?>
    <div class="flash flash-error" role="alert"><p><?= e(__('site.validation.summary')) ?></p></div>
<?php endif; ?>

    <form method="post" action="<?= e(url('/register')) ?>" class="form" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">

        <div class="field">
            <label for="company"><?= e(__('site.register.company')) ?></label>
            <input id="company" name="company" type="text" autocomplete="organization" required maxlength="190"
                   value="<?= e($old['company'] ?? '') ?>"<?= $field('company') !== '' ? ' aria-invalid="true" aria-describedby="company-error"' : '' ?>>
<?php if ($field('company') !== ''): ?>
            <p class="field-error" id="company-error"><?= e($field('company')) ?></p>
<?php endif; ?>
        </div>

        <div class="field">
            <label for="email"><?= e(__('site.form.email')) ?></label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="254" inputmode="email"
                   value="<?= e($old['email'] ?? '') ?>"<?= $field('email') !== '' ? ' aria-invalid="true" aria-describedby="email-error"' : '' ?>>
<?php if ($field('email') !== ''): ?>
            <p class="field-error" id="email-error"><?= e($field('email')) ?></p>
<?php endif; ?>
        </div>

        <div class="field">
            <label for="password"><?= e(__('site.form.password')) ?></label>
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

        <button type="submit" class="button button-block"><?= e(__('site.register.submit')) ?></button>
    </form>

    <p class="auth-alt"><?= e(__('site.register.have_account')) ?> <a href="<?= e(url('/login')) ?>"><?= e(__('site.register.login_link')) ?></a></p>
</section>
