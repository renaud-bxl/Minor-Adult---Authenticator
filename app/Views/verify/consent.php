<?php

declare(strict_types=1);
/**
 * Étape 1 : information et consentement explicite (RGPD art. 9). ⚖️ Textes à faire valider par un juriste.
 *
 * @var \App\Models\Project             $project
 * @var \App\Models\VerificationSession $session
 * @var string                          $state
 * @var string                          $actionBase
 * @var string                          $query
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'consent']) ?>
    <h1 id="verify-title"><?= e(__('module.consent.title')) ?></h1>
    <p class="lead"><?= e(__('module.consent.intro', ['project' => $project->name, 'age' => $session->minAge])) ?></p>

    <div class="info-box">
        <h2><?= e(__('module.consent.info_title')) ?></h2>
        <ul>
            <li><?= e(__('module.consent.info_email')) ?></li>
            <li><?= e(__('module.consent.info_biometric')) ?></li>
            <li><?= e(__('module.consent.info_local')) ?></li>
            <li><?= e(__('module.consent.info_shared', ['project' => $project->name])) ?></li>
            <li><?= e(__('module.consent.info_rights', ['project' => $project->name])) ?></li>
        </ul>
    </div>

    <form method="post" action="<?= e($actionBase . '/consent' . $query) ?>" class="form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <label class="checkbox">
            <input type="checkbox" name="consent" value="1" required>
            <span><?= e(__('module.consent.checkbox')) ?></span>
        </label>
        <button type="submit" class="button button-block"><?= e(__('module.consent.submit')) ?></button>
    </form>
</section>
