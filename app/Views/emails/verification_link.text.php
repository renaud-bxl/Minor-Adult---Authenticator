<?php

declare(strict_types=1);

/** @var string $url @var int $minutes @var string $project */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.verification_link.intro', ['project' => $project, 'app' => (string) config('app.name')]) ?>


<?= $url ?>


<?= __('emails.verification_link.expiry', ['minutes' => $minutes]) ?>

<?= __('emails.verification_code.ignore') ?>

-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

