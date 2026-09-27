<?php

declare(strict_types=1);

/** @var string $verifyUrl @var int $hours */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.verify_email.intro', ['app' => (string) config('app.name')])) ?></p>
<p style="margin:28px 0;"><a href="<?= e($verifyUrl) ?>" style="background:#1f4fd1;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block;"><?= e(__('emails.verify_email.action')) ?></a></p>
<p><?= e(__('emails.verify_email.expiry', ['hours' => $hours])) ?></p>
<p style="font-size:13px;color:#5b6475;"><?= e(__('emails.common.link_fallback')) ?><br><a href="<?= e($verifyUrl) ?>" style="color:#1f4fd1;word-break:break-all;"><?= e($verifyUrl) ?></a></p>
<p><?= e(__('emails.verify_email.ignore')) ?></p>
