<?php

declare(strict_types=1);
/**
 * Retour du mode « redirection » : le serveur de la boutique vérifie le jeton (JWT) puis confirme
 * par l'API et les webhooks.
 *
 * @var string                     $sessionId
 * @var array<string, mixed>|null  $claims
 * @var string|null                $problem  token_replayed (jti déjà consommé) | owner_mismatch
 * @var array<string, mixed>|null  $api
 * @var list<array<string, mixed>> $webhooks
 */
$json = static fn (mixed $v): string => (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$adult = $claims !== null && ($claims['is_adult'] ?? false) === true;
?>
<section class="shop-product demo-return">
    <h1><?= e(__('site.demo.return_title')) ?></h1>
<?php if ($claims === null): ?>
    <div class="notice notice-warning" role="alert" data-return-problem="<?= e($problem ?? 'token_invalid') ?>"><p><?= e(__(match ($problem ?? null) {
        'token_replayed' => 'site.demo.token_replayed',
        'owner_mismatch' => 'site.demo.owner_mismatch',
        default => 'site.demo.token_invalid',
    })) ?></p></div>
<?php else: ?>
    <div class="notice <?= $adult ? 'notice-success' : 'notice-warning' ?>" role="status" data-return-status="<?= e((string) ($claims['status'] ?? '')) ?>">
        <p><strong><?= e($adult ? __('site.demo.return_adult') : __('site.demo.return_not_adult')) ?></strong></p>
        <p><?= e(__('site.demo.token_valid')) ?></p>
    </div>
    <h2><?= e(__('site.demo.token_claims')) ?></h2>
    <pre class="log"><?= e($json($claims)) ?></pre>
<?php endif; ?>
    <h2><?= e(__('site.demo.step_confirm')) ?></h2>
    <pre class="log"><?= e($api === null ? __('site.demo.none_yet') : $json($api)) ?></pre>
    <h2><?= e(__('site.demo.step_webhooks')) ?></h2>
    <pre class="log"><?= e($webhooks === [] ? __('site.demo.webhooks_pending') : $json($webhooks)) ?></pre>
    <p><a class="shop-button" href="/demo?lang=<?= e(locale()) ?>"><?= e(__('site.demo.back')) ?></a></p>
</section>
