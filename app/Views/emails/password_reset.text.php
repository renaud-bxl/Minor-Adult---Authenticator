<?php

declare(strict_types=1);

/** @var string $resetUrl @var int $minutes */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.password_reset.intro', ['app' => (string) config('app.name')]) ?>


<?= $resetUrl ?>


<?= __('emails.password_reset.expiry', ['minutes' => $minutes]) ?>

<?= __('emails.password_reset.ignore') ?>

-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

