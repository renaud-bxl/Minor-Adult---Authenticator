<?php

declare(strict_types=1);

/** @var string $verifyUrl @var int $hours */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.verify_email.intro', ['app' => (string) config('app.name')]) ?>


<?= $verifyUrl ?>


<?= __('emails.verify_email.expiry', ['hours' => $hours]) ?>

<?= __('emails.verify_email.ignore') ?>

-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

