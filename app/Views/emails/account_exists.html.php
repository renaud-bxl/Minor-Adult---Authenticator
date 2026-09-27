<?php

declare(strict_types=1);

/** @var string $loginUrl @var string $resetUrl */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.account_exists.intro', ['app' => (string) config('app.name')])) ?></p>
<p style="margin:28px 0;"><a href="<?= e($loginUrl) ?>" style="background:#1f4fd1;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block;"><?= e(__('emails.account_exists.login_action')) ?></a></p>
<p><?= e(__('emails.account_exists.forgot')) ?> <a href="<?= e($resetUrl) ?>" style="color:#1f4fd1;"><?= e(__('emails.account_exists.reset_action')) ?></a></p>
<p><?= e(__('emails.account_exists.ignore')) ?></p>
