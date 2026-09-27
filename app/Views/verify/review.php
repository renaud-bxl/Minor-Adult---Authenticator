<?php

declare(strict_types=1);
/**
 * Session en revue manuelle : la décision automatique était incertaine et le projet a choisi une
 * vérification par un opérateur. Aucune image n'est conservée pour cette revue.
 *
 * @var \App\Models\Project $project
 */
?>
<section class="verify-card result result-neutral" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'result']) ?>
    <div class="result-icon" aria-hidden="true">…</div>
    <h1 id="verify-title" data-result-status="review"><?= e(__('module.review.title')) ?></h1>
    <p><?= e(__('module.review.text', ['project' => $project->name, 'hours' => (int) config('biometrics.review_ttl_hours')])) ?></p>
    <div class="actions actions-stack">
        <button type="button" class="button button-secondary button-block" data-veriage-close hidden><?= e(__('module.page.close')) ?></button>
    </div>
</section>
