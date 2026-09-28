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
 * @var bool|null                 $canClose aucun retour possible vers le site : inviter à fermer la page
 */
$status = (string) $result['status'];
// Explication propre au motif d'échec (clés littérales ; motifs techniques sans détail).
$reasonKeys = [
    'document_unreadable' => 'module.result.reason.document_unreadable',
    'document_expired' => 'module.result.reason.document_expired',
    'document_inconsistent' => 'module.result.reason.document_inconsistent',
    'document_unsupported' => 'module.result.reason.document_unsupported',
    'liveness_failed' => 'module.result.reason.liveness_failed',
    'face_mismatch' => 'module.result.reason.face_mismatch',
    'face_not_found' => 'module.result.reason.face_not_found',
    'capture_rejected' => 'module.result.reason.capture_rejected',
    'capture_attempts_exceeded' => 'module.result.reason.capture_attempts_exceeded',
    'manual_review_rejected' => 'module.result.reason.manual_review_rejected',
];
$reasonKey = $status === 'failed' ? ($reasonKeys[(string) $result['failure_reason']] ?? null) : null;
[$titleKey, $textKey, $tone] = match (true) {
    $status === 'verified' && $result['is_adult'] === true => ['module.result.verified_title', 'module.result.verified_text', 'success'],
    $status === 'verified' => ['module.result.minor_title', 'module.result.minor_text', 'warning'],
    $status === 'expired' => ['module.result.expired_title', 'module.result.expired_text', 'neutral'],
    $result['failure_reason'] === 'code_attempts_exceeded' => ['module.result.failed_title', 'module.result.failed_code_text', 'error'],
    default => ['module.result.failed_title', 'module.result.failed_text', 'error'],
};
?>
<section class="verify-card result result-<?= e($tone) ?>" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'result']) ?>
    <div class="result-icon" aria-hidden="true"><?= $tone === 'success' ? '✓' : ($tone === 'neutral' ? '…' : '!') ?></div>
    <h1 id="verify-title" data-result-status="<?= e($status) ?>"><?= e(__($titleKey)) ?></h1>
    <p><?= e(__($textKey, ['project' => $project->name, 'age' => (int) $result['min_age']])) ?></p>
<?php if ($reasonKey !== null): ?>
    <p class="muted" data-failure-reason="<?= e((string) $result['failure_reason']) ?>"><?= e(__($reasonKey)) ?></p>
<?php endif; ?>
<?php if ($status === 'verified' && $result['reused'] === true): ?>
    <p class="muted"><?= e(__('module.result.reused')) ?></p>
<?php endif; ?>

<?php if (!empty($canClose)): ?>
    <p class="muted" data-can-close><?= e(__('module.result.can_close')) ?></p>
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
