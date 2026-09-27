<?php

declare(strict_types=1);

/** @var string $code @var int $minutes @var string $project */ ?>
<p><?= e(__('emails.common.greeting')) ?></p>
<p><?= e(__('emails.verification_code.intro', ['project' => $project, 'app' => (string) config('app.name')])) ?></p>
<p style="margin:28px 0;font-size:32px;font-weight:700;letter-spacing:8px;font-family:ui-monospace,Menlo,Consolas,monospace;"><?= e($code) ?></p>
<p><?= e(__('emails.verification_code.expiry', ['minutes' => $minutes])) ?></p>
<p><?= e(__('emails.verification_code.never_share')) ?></p>
<p><?= e(__('emails.verification_code.ignore')) ?></p>
