<?php

declare(strict_types=1);

/** @var string $resetUrl */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.password_changed.intro', ['app' => (string) config('app.name')]) ?>

<?= __('emails.password_changed.sessions') ?>


<?= __('emails.password_changed.not_you') ?> <?= $resetUrl ?>


-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

