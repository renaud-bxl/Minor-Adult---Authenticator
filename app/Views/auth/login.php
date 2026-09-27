<?php

declare(strict_types=1);
/**
 * @var string         $email
 * @var string|null    $error
 * @var \App\Core\Csrf $csrf
 */
?>
<section class="auth-card">
    <h1><?= e(__('site.login.title')) ?></h1>
    <p class="muted"><?= e(__('site.login.intro')) ?></p>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><p><?= e($error) ?></p></div>
<?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" class="form">
        <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">

        <div class="field">
            <label for="email"><?= e(__('site.form.email')) ?></label>
            <input id="email" name="email" type="email" autocomplete="username" required maxlength="254" inputmode="email" value="<?= e($email) ?>">
        </div>

        <div class="field">
            <div class="field-row">
                <label for="password"><?= e(__('site.form.password')) ?></label>
                <a class="small-link" href="<?= e(url('/forgot-password')) ?>"><?= e(__('site.login.forgot_link')) ?></a>
            </div>
            <div class="password-wrap">
                <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
                <button type="button" class="password-toggle" data-password-toggle="password" hidden
                        data-label-show="<?= e(__('site.form.show_password')) ?>" data-label-hide="<?= e(__('site.form.hide_password')) ?>"><?= e(__('site.form.show_password')) ?></button>
            </div>
        </div>

        <button type="submit" class="button button-block"><?= e(__('site.login.submit')) ?></button>
    </form>

    <p class="auth-alt"><?= e(__('site.login.no_account')) ?> <a href="<?= e(url('/register')) ?>"><?= e(__('site.login.register_link')) ?></a></p>
</section>
