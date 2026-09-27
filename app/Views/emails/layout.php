<?php

declare(strict_types=1);
/**
 * Gabarit HTML commun des e-mails (styles en ligne : les clients mail ignorent les feuilles externes).
 *
 * @var string $content
 * @var string $subject
 */
$appName = (string) config('app.name');
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f3f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1b2333;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px;">
                <tr>
                    <td style="font-size:20px;font-weight:700;color:#1f4fd1;padding-bottom:16px;"><?= e($appName) ?></td>
                </tr>
                <tr>
                    <td style="font-size:16px;line-height:1.6;"><?= $content ?></td>
                </tr>
                <tr>
                    <td style="font-size:12px;line-height:1.5;color:#5b6475;padding-top:24px;border-top:1px solid #e3e7ef;">
                        <?= e(__('emails.common.footer', ['app' => $appName])) ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
