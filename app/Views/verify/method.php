<?php

declare(strict_types=1);
/**
 * Étape 3 : choix de la méthode de vérification.
 *
 * @var list<\App\Verification\VerificationMethodInterface> $methods
 * @var string                                               $state
 * @var string                                               $actionBase
 * @var string                                               $query
 */
use App\Verification\Methods\LocalBiometricsProvider;
use App\Verification\Methods\MockProvider;

// Libellés par méthode (clés littérales ; l'eID de la phase 4 s'ajoutera ici).
$labels = [
    MockProvider::ID => ['module.method.mock.title', 'module.method.mock.description'],
    LocalBiometricsProvider::ID => ['module.method.id_document_face.title', 'module.method.id_document_face.description'],
];
$outcomes = [
    'adult' => 'module.method.mock.adult',
    'minor' => 'module.method.mock.minor',
    'fail' => 'module.method.mock.fail',
];
?>
<section class="verify-card" aria-labelledby="verify-title">
    <?= partial('verify/_steps', ['current' => 'method']) ?>
    <h1 id="verify-title"><?= e(__('module.method.title')) ?></h1>

<?php if ($methods === []): ?>
    <p class="flash flash-info"><?= e(__('module.method.none')) ?></p>
<?php endif; ?>

<?php foreach ($methods as $method): ?>
<?php [$titleKey, $descriptionKey] = $labels[$method->id()] ?? ['module.method.generic.title', 'module.method.generic.description']; ?>
<?php if ($method->id() === LocalBiometricsProvider::ID): ?>
    <div class="method-card">
        <h2><?= e(__($titleKey)) ?></h2>
        <p class="muted"><?= e(__($descriptionKey)) ?></p>
        <a class="button button-block" href="<?= e($actionBase . '/document' . $query) ?>" data-method="<?= e($method->id()) ?>"><?= e(__('module.method.id_document_face.start')) ?></a>
    </div>
<?php continue; endif; ?>
    <form method="post" action="<?= e($actionBase . '/method/' . $method->id() . $query) ?>" class="method-card"<?= $method->requiresTopLevelWindow() ? ' data-toplevel' : '' ?>>
        <input type="hidden" name="_state" value="<?= e($state) ?>">
        <h2><?= e(__($titleKey)) ?></h2>
        <p class="muted"><?= e(__($descriptionKey)) ?></p>
<?php if ($method->id() === MockProvider::ID): ?>
        <fieldset class="choice-group">
            <legend><?= e(__('module.method.mock.legend')) ?></legend>
<?php foreach ($outcomes as $value => $key): ?>
            <label class="choice">
                <input type="radio" name="outcome" value="<?= e($value) ?>" required<?= $value === 'adult' ? ' checked' : '' ?>>
                <span><?= e(__($key)) ?></span>
            </label>
<?php endforeach; ?>
        </fieldset>
<?php endif; ?>
        <label class="checkbox checkbox-small">
            <input type="checkbox" name="share" value="1">
            <span><?= e(__('module.method.share_opt_in')) ?></span>
        </label>
        <button type="submit" class="button button-block"><?= e(__('module.method.submit')) ?></button>
    </form>
<?php endforeach; ?>
</section>
