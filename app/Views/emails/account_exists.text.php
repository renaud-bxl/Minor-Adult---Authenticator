<?php

declare(strict_types=1);

/** @var string $loginUrl @var string $resetUrl */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.account_exists.intro', ['app' => (string) config('app.name')]) ?>


<?= __('emails.account_exists.login_action') ?>

<?= $loginUrl ?>


<?= __('emails.account_exists.reset_action') ?>

<?= $resetUrl ?>


<?= __('emails.account_exists.ignore') ?>

-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

