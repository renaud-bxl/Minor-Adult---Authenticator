<?php

declare(strict_types=1);

/** Page d'accueil (vitrine minimale ; le site complet arrive en phase 9). */ ?>
<section class="hero">
    <p class="eyebrow"><?= e(__('site.home.eyebrow')) ?></p>
    <h1><?= e(__('site.home.title')) ?></h1>
    <p class="lead"><?= e(__('site.home.lead')) ?></p>
    <div class="actions">
<?php if (!empty($isAuthenticated)): ?>
        <a class="button" href="<?= e(url('/dashboard')) ?>"><?= e(__('site.home.cta_dashboard')) ?></a>
<?php else: ?>
        <a class="button" href="<?= e(url('/register')) ?>"><?= e(__('site.home.cta_register')) ?></a>
        <a class="button button-secondary" href="<?= e(url('/login')) ?>"><?= e(__('site.home.cta_login')) ?></a>
<?php endif; ?>
    </div>
</section>

<section class="features" aria-labelledby="features-title">
    <h2 id="features-title" class="section-title"><?= e(__('site.home.features_title')) ?></h2>
    <div class="grid-3">
        <article class="card">
            <h3><?= e(__('site.home.feature_privacy_title')) ?></h3>
            <p><?= e(__('site.home.feature_privacy_text')) ?></p>
        </article>
        <article class="card">
            <h3><?= e(__('site.home.feature_inhouse_title')) ?></h3>
            <p><?= e(__('site.home.feature_inhouse_text')) ?></p>
        </article>
        <article class="card">
            <h3><?= e(__('site.home.feature_europe_title')) ?></h3>
            <p><?= e(__('site.home.feature_europe_text')) ?></p>
        </article>
    </div>
</section>

<section class="steps" aria-labelledby="steps-title">
    <h2 id="steps-title" class="section-title"><?= e(__('site.home.steps_title')) ?></h2>
    <ol class="step-list">
        <li><strong><?= e(__('site.home.step1_title')) ?></strong><span><?= e(__('site.home.step1_text')) ?></span></li>
        <li><strong><?= e(__('site.home.step2_title')) ?></strong><span><?= e(__('site.home.step2_text')) ?></span></li>
        <li><strong><?= e(__('site.home.step3_title')) ?></strong><span><?= e(__('site.home.step3_text')) ?></span></li>
    </ol>
</section>
