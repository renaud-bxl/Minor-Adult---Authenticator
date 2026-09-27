<?php

declare(strict_types=1);
/**
 * @var string $company
 * @var string $email
 * @var string $role
 * @var string $memberSince
 */
?>
<section class="dashboard">
    <h1><?= e(__('site.dashboard.welcome', ['company' => $company])) ?></h1>
    <p class="lead"><?= e(__('site.dashboard.intro')) ?></p>

    <div class="grid-2">
        <article class="card">
            <h2><?= e(__('site.dashboard.account_title')) ?></h2>
            <dl class="details">
                <dt><?= e(__('site.dashboard.company')) ?></dt>
                <dd><?= e($company) ?></dd>
                <dt><?= e(__('site.form.email')) ?></dt>
                <dd><?= e($email) ?></dd>
                <dt><?= e(__('site.dashboard.role')) ?></dt>
                <dd><?= e($role) ?></dd>
                <dt><?= e(__('site.dashboard.member_since')) ?></dt>
                <dd><?= e($memberSince) ?></dd>
            </dl>
        </article>
        <article class="card card-muted">
            <h2><?= e(__('site.dashboard.next_title')) ?></h2>
            <p><?= e(__('site.dashboard.next_text')) ?></p>
        </article>
    </div>
</section>
