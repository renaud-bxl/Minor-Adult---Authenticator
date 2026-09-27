<?php

declare(strict_types=1);
/**
 * Page de démonstration : boutique fictive + « coulisses » de l'intégration.
 *
 * @var bool   $configured
 * @var string $widgetUrl
 * @var string $token
 * @var string $defaultEmail
 * @var string $price
 */
$modes = [
    'modal' => 'site.demo.mode_modal',
    'popup' => 'site.demo.mode_popup',
    'iframe' => 'site.demo.mode_iframe',
    'redirect' => 'site.demo.mode_redirect',
];
?>
<div class="shop-grid" id="demo" data-token="<?= e($token) ?>" data-lang="<?= e(locale()) ?>"
     data-label-created="<?= e(__('site.demo.log_created')) ?>" data-label-error="<?= e(__('site.demo.log_error')) ?>"
     data-label-waiting="<?= e(__('site.demo.waiting')) ?>" data-label-confirmed="<?= e(__('site.demo.confirmed')) ?>"
     data-label-open="<?= e(__('site.demo.open_widget')) ?>" data-label-none="<?= e(__('site.demo.none_yet')) ?>">
    <section class="shop-product" aria-labelledby="product-title">
        <div class="product-visual" aria-hidden="true">🍾</div>
        <h1 id="product-title"><?= e(__('site.demo.product_name')) ?></h1>
        <p class="product-price"><?= e($price) ?></p>
        <p><?= e(__('site.demo.product_text')) ?></p>

<?php if (!$configured): ?>
        <div class="notice notice-warning" role="alert">
            <p><strong><?= e(__('site.demo.not_configured_title')) ?></strong></p>
            <p><?= e(__('site.demo.not_configured_text')) ?></p>
            <pre><code>php bin/project.php demo &gt;&gt; .env</code></pre>
        </div>
<?php endif; ?>

        <form id="demo-form" class="demo-form" novalidate>
            <div class="field">
                <label for="demo-email"><?= e(__('site.demo.email_label')) ?></label>
                <input id="demo-email" name="email" type="email" required value="<?= e($defaultEmail) ?>" autocomplete="off">
                <p class="hint"><?= e(__('site.demo.email_hint')) ?></p>
            </div>
            <div class="field">
                <label for="demo-age"><?= e(__('site.demo.age_label')) ?></label>
                <select id="demo-age" name="min_age">
                    <option value="16">16</option>
                    <option value="18" selected>18</option>
                    <option value="21">21</option>
                </select>
            </div>
            <fieldset class="field">
                <legend><?= e(__('site.demo.mode_label')) ?></legend>
<?php foreach ($modes as $value => $key): ?>
                <label class="radio"><input type="radio" name="mode" value="<?= e($value) ?>"<?= $value === 'modal' ? ' checked' : '' ?>> <?= e(__($key)) ?></label>
<?php endforeach; ?>
            </fieldset>
            <button type="submit" class="shop-button" id="demo-create"<?= $configured ? '' : ' disabled' ?>><?= e(__('site.demo.create_session')) ?></button>
            <button type="button" class="shop-button shop-button-alt" id="demo-open" disabled><?= e(__('site.demo.open_widget')) ?></button>
        </form>
        <div id="demo-frame" class="demo-frame" hidden></div>
    </section>

    <aside class="backstage" aria-labelledby="backstage-title">
        <h2 id="backstage-title"><?= e(__('site.demo.backstage_title')) ?></h2>
        <p class="hint"><?= e(__('site.demo.backstage_intro')) ?></p>

        <h3><?= e(__('site.demo.step_api')) ?></h3>
        <pre class="log" id="log-api" aria-live="polite"><?= e(__('site.demo.none_yet')) ?></pre>

        <h3><?= e(__('site.demo.step_events')) ?></h3>
        <ol class="events" id="log-events" aria-live="polite"></ol>

        <h3><?= e(__('site.demo.step_confirm')) ?></h3>
        <p class="hint"><?= e(__('site.demo.confirm_hint')) ?></p>
        <pre class="log" id="log-confirm" aria-live="polite"><?= e(__('site.demo.none_yet')) ?></pre>

        <h3><?= e(__('site.demo.step_webhooks')) ?></h3>
        <p class="hint"><?= e(__('site.demo.webhooks_hint')) ?></p>
        <pre class="log" id="log-webhooks" aria-live="polite"><?= e(__('site.demo.none_yet')) ?></pre>
    </aside>
</div>
<script src="<?= e($widgetUrl) ?>"></script>
