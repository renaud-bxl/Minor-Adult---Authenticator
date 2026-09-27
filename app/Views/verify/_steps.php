<?php

declare(strict_types=1);

/** Indicateur d'étapes. @var string $current consent|email|method|result */
$steps = [
    'consent' => __('module.steps.consent'),
    'email' => __('module.steps.email'),
    'method' => __('module.steps.method'),
    'result' => __('module.steps.result'),
];
$reached = true;
?>
<ol class="verify-steps" aria-label="<?= e(__('module.steps.label')) ?>">
<?php foreach ($steps as $key => $label): ?>
    <li class="<?= $key === $current ? 'is-current' : ($reached ? 'is-done' : '') ?>"<?= $key === $current ? ' aria-current="step"' : '' ?>><span><?= e($label) ?></span></li>
<?php if ($key === $current) { $reached = false; } ?>
<?php endforeach; ?>
</ol>
