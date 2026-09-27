<?php

declare(strict_types=1);

/** @var string $resetUrl @var int $minutes */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.password_reset.intro', ['app' => (string) config('app.name')])) ?></p>
<p style="margin:28px 0;"><a href="<?= e($resetUrl) ?>" style="background:#1f4fd1;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block;"><?= e(__('emails.password_reset.action')) ?></a></p>
<p><?= e(__('emails.password_reset.expiry', ['minutes' => $minutes])) ?></p>
<p style="font-size:13px;color:#5b6475;"><?= e(__('emails.common.link_fallback')) ?><br><a href="<?= e($resetUrl) ?>" style="color:#1f4fd1;word-break:break-all;"><?= e($resetUrl) ?></a></p>
<p><?= e(__('emails.password_reset.ignore')) ?></p>
