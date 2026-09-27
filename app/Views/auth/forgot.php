<?php

declare(strict_types=1);
/**
 * @var string         $email
 * @var string|null    $error
 * @var \App\Core\Csrf $csrf
 */
?>
<section class="auth-card">
    <h1><?= e(__('site.forgot.title')) ?></h1>
    <p class="muted"><?= e(__('site.forgot.intro')) ?></p>

<?php if ($error !== null): ?>
    <div class="flash flash-error" role="alert"><p><?= e($error) ?></p></div>
<?php endif; ?>

    <form method="post" action="<?= e(url('/forgot-password')) ?>" class="form">
        <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">
        <div class="field">
            <label for="email"><?= e(__('site.form.email')) ?></label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="254" inputmode="email" value="<?= e($email) ?>">
        </div>
        <button type="submit" class="button button-block"><?= e(__('site.forgot.submit')) ?></button>
    </form>

    <p class="auth-alt"><a href="<?= e(url('/login')) ?>"><?= e(__('site.forgot.back_to_login')) ?></a></p>
</section>
