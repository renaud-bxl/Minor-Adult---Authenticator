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
<?php $state = $key === $current ? 'current' : ($reached ? 'done' : 'todo'); ?>
    <li class="is-<?= e($state) ?>"<?= $key === $current ? ' aria-current="step"' : '' ?>>
        <span><?php if ($state === 'done'): ?><span class="step-check" aria-hidden="true">✓ </span><?php endif; ?><?= e($label) ?></span>
        <span class="visually-hidden"><?= e(__($state === 'done' ? 'module.steps.state_done' : ($state === 'current' ? 'module.steps.state_current' : 'module.steps.state_todo'))) ?></span>
    </li>
<?php if ($key === $current) { $reached = false; } ?>
<?php endforeach; ?>
</ol>
