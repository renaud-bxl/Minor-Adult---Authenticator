<?php

declare(strict_types=1);

/** @var string $resetUrl */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.password_changed.intro', ['app' => (string) config('app.name')])) ?></p>
<p><?= e(__('emails.password_changed.sessions')) ?></p>
<p><?= e(__('emails.password_changed.not_you')) ?> <a href="<?= e($resetUrl) ?>" style="color:#1f4fd1;"><?= e(__('emails.password_changed.action')) ?></a></p>
