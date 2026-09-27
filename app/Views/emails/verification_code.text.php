<?php

declare(strict_types=1);

/** @var string $code @var int $minutes @var string $project */ ?>
<?= __('emails.common.greeting') ?>


<?= __('emails.verification_code.intro', ['project' => $project, 'app' => (string) config('app.name')]) ?>


    <?= $code ?>


<?= __('emails.verification_code.expiry', ['minutes' => $minutes]) ?>

<?= __('emails.verification_code.never_share') ?>

<?= __('emails.verification_code.ignore') ?>

-- 
<?= __('emails.common.footer', ['app' => (string) config('app.name')]) ?>

