<?php

declare(strict_types=1);
/**
 * Méthode « pièce d'identité + visage » : consentement explicite au traitement de données BIOMÉTRIQUES
 * (RGPD art. 9.2.a), sur un écran dédié, juste avant la capture. ⚖️ Textes à faire valider par un juriste.
 *
 * @var \App\Models\Project $project
 * @var string              $state
 * @var string              $actionBase
 * @var string              $query
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'method']) ?>
    <h1 id="verify-title"><?= e(__('module.biometric.consent_title')) ?></h1>
    <p class="lead"><?= e(__('module.biometric.intro')) ?></p>

    <div class="info-box">
        <h2><?= e(__('module.biometric.what_title')) ?></h2>
        <ul>
            <li><?= e(__('module.biometric.what_document')) ?></li>
            <li><?= e(__('module.biometric.what_face')) ?></li>
        </ul>
        <h2><?= e(__('module.biometric.how_title')) ?></h2>
        <ul>
            <li><?= e(__('module.biometric.how_local')) ?></li>
            <li><?= e(__('module.biometric.how_result', ['project' => $project->name])) ?></li>
            <li><?= e(__('module.biometric.how_basis')) ?></li>
        </ul>
    </div>

    <form method="post" action="<?= e($actionBase . '/document/consent' . $query) ?>" class="form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <label class="checkbox">
            <input type="checkbox" name="consent" value="1" required>
            <span><?= e(__('module.biometric.checkbox')) ?></span>
        </label>
        <label class="checkbox checkbox-small">
            <input type="checkbox" name="share" value="1">
            <span><?= e(__('module.method.share_opt_in')) ?></span>
        </label>
        <button type="submit" class="button button-block"><?= e(__('module.biometric.submit')) ?></button>
    </form>
    <p class="actions-center"><a href="<?= e($actionBase . $query . ($query === '' ? '?' : '&') . 'shared=declined') ?>"><?= e(__('module.biometric.other_method')) ?></a></p>
</section>
