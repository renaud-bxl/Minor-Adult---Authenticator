<?php

declare(strict_types=1);
/**
 * Étape 4 : résultat (ou session expirée). En mode intégré, le résultat est transmis à la page
 * parente par postMessage (verify-page.js), uniquement vers l'origine vérifiée.
 *
 * @var \App\Models\Project       $project
 * @var array<string, mixed>      $result
 * @var string|null               $returnHref
 * @var array<string, mixed>|null $message
 */
$status = (string) $result['status'];
[$titleKey, $textKey, $tone] = match (true) {
    $status === 'verified' && $result['is_adult'] === true => ['module.result.verified_title', 'module.result.verified_text', 'success'],
    $status === 'verified' => ['module.result.minor_title', 'module.result.minor_text', 'warning'],
    $status === 'expired' => ['module.result.expired_title', 'module.result.expired_text', 'neutral'],
    default => ['module.result.failed_title', 'module.result.failed_text', 'error'],
};
?>
<section class="verify-card result result-<?= e($tone) ?>" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'result']) ?>
    <div class="result-icon" aria-hidden="true"><?= $tone === 'success' ? '✓' : ($tone === 'neutral' ? '…' : '!') ?></div>
    <h1 id="verify-title" data-result-status="<?= e($status) ?>"><?= e(__($titleKey)) ?></h1>
    <p><?= e(__($textKey, ['project' => $project->name, 'age' => (int) $result['min_age']])) ?></p>
<?php if ($status === 'verified' && $result['reused'] === true): ?>
    <p class="muted"><?= e(__('module.result.reused')) ?></p>
<?php endif; ?>

    <div class="actions actions-stack">
<?php if ($returnHref !== null): ?>
        <a class="button button-block" href="<?= e($returnHref) ?>" data-veriage-return><?= e(__('module.result.return', ['project' => $project->name])) ?></a>
<?php endif; ?>
        <button type="button" class="button button-secondary button-block" data-veriage-close hidden><?= e(__('module.page.close')) ?></button>
    </div>
<?php if ($message !== null): ?>
    <div id="veriage-result" hidden data-message="<?= e(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>"></div>
<?php endif; ?>
</section>
