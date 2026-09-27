<?php

declare(strict_types=1);
/**
 * Réutilisation d'une vérification d'un autre site : consentement explicite de l'utilisateur.
 *
 * @var \App\Models\Project $project
 * @var string              $sharedVerifiedAt
 * @var string              $state
 * @var string              $actionBase
 * @var string              $query
 */
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'method']) ?>
    <h1 id="verify-title"><?= e(__('module.shared.title')) ?></h1>
    <p><?= e(__('module.shared.intro', ['date' => $sharedVerifiedAt])) ?></p>
    <p><?= e(__('module.shared.question', ['project' => $project->name])) ?></p>
    <form method="post" action="<?= e($actionBase . '/shared' . $query) ?>" class="form">
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <button type="submit" name="choice" value="accept" class="button button-block"><?= e(__('module.shared.accept')) ?></button>
        <button type="submit" name="choice" value="decline" class="button button-secondary button-block"><?= e(__('module.shared.decline')) ?></button>
    </form>
</section>
