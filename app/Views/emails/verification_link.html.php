<?php

declare(strict_types=1);

/** @var string $url @var int $minutes @var string $project */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.verification_link.intro', ['project' => $project, 'app' => (string) config('app.name')])) ?></p>
<p style="margin:28px 0;"><a href="<?= e($url) ?>" style="background:#1f4fd1;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;display:inline-block;"><?= e(__('emails.verification_link.action')) ?></a></p>
<p><?= e(__('emails.verification_link.expiry', ['minutes' => $minutes])) ?></p>
<p style="font-size:13px;color:#5b6475;"><?= e(__('emails.common.link_fallback')) ?><br><a href="<?= e($url) ?>" style="color:#1f4fd1;word-break:break-all;"><?= e($url) ?></a></p>
<p><?= e(__('emails.verification_code.ignore')) ?></p>
