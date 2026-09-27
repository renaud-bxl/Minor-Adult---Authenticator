<?php

declare(strict_types=1);
/**
 * Méthode « pièce d'identité + visage » : capture guidée (public/assets/js/capture.js).
 * Recto puis verso (ou page photo du passeport) par la caméra, ou par envoi d'un fichier en secours ;
 * selfie OBLIGATOIREMENT en direct, avec les défis tirés par le serveur. Les images sont chiffrées dans
 * le navigateur (AES-GCM) avant l'envoi et ne sont jamais conservées.
 *
 * @var \App\Models\Project  $project
 * @var string               $state
 * @var string               $actionBase
 * @var string               $query
 * @var array<string, int>   $settings
 */
$labels = [
    'front_title' => __('module.capture.front_title'),
    'front_help' => __('module.capture.front_help'),
    'passport_title' => __('module.capture.passport_title'),
    'passport_help' => __('module.capture.passport_help'),
    'back_title' => __('module.capture.back_title'),
    'back_help' => __('module.capture.back_help'),
    'camera_starting' => __('module.capture.camera_starting'),
    'camera_unavailable' => __('module.capture.camera_unavailable'),
    'camera_denied' => __('module.capture.camera_denied'),
    'selfie_camera_required' => __('module.capture.selfie_camera_required'),
    'quality_ok' => __('module.capture.quality_ok'),
    'quality_blurry' => __('module.capture.quality_blurry'),
    'quality_dark' => __('module.capture.quality_dark'),
    'quality_bright' => __('module.capture.quality_bright'),
    'quality_small' => __('module.capture.quality_small'),
    'look' => __('module.capture.look'),
    'turn_left' => __('module.capture.challenge.turn_left'),
    'turn_right' => __('module.capture.challenge.turn_right'),
    'blink' => __('module.capture.challenge.blink'),
    'progress' => __('module.capture.progress'),
    'sending' => __('module.capture.sending'),
    'attempts_left' => __('module.capture.attempts_left'),
    'error_generic' => __('module.capture.error.generic'),
    'error_network' => __('module.capture.error.network'),
    'capture_expired' => __('module.capture.error.capture_expired'),
    'capture_too_fast' => __('module.capture.error.capture_too_fast'),
    'capture_invalid' => __('module.capture.error.capture_invalid'),
    'capture_frames_invalid' => __('module.capture.error.capture_invalid'),
    'capture_image_invalid' => __('module.capture.error.capture_image_invalid'),
    'capture_too_large' => __('module.capture.error.capture_too_large'),
    'capture_attempts_exceeded' => __('module.capture.error.capture_attempts_exceeded'),
    'capture_not_allowed' => __('module.capture.error.capture_not_allowed'),
    'biometrics_unavailable' => __('module.capture.error.biometrics_unavailable'),
];
$json = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sessionHref = $actionBase . $query;
?>
<section class="verify-card capture" aria-labelledby="verify-title" id="capture"
         data-start-url="<?= e($actionBase . '/document/start' . $query) ?>"
         data-submit-url="<?= e($actionBase . '/document/submit' . $query) ?>"
         data-session-url="<?= e($sessionHref) ?>"
         data-state="<?= e($state) ?>"
         data-settings="<?= e($json($settings)) ?>"
         data-labels="<?= e($json($labels)) ?>">
    <?= partial('verify/_steps', ['current' => 'method']) ?>
    <h1 id="verify-title"><?= e(__('module.capture.title')) ?></h1>
    <noscript><p class="flash flash-error"><?= e(__('module.capture.noscript')) ?></p></noscript>

    <p class="visually-hidden" id="capture-live" aria-live="assertive" aria-atomic="true"></p>
    <div class="flash flash-error" id="capture-error" role="alert" hidden>
        <p data-error-text></p>
        <p class="muted" data-attempts hidden></p>
        <button type="button" class="button button-secondary" data-action="retry" hidden><?= e(__('module.capture.retry')) ?></button>
    </div>

    <form class="capture-panel" data-panel="type" hidden>
        <fieldset class="choice-group">
            <legend><?= e(__('module.capture.doc_type_legend')) ?></legend>
            <label class="choice">
                <input type="radio" name="document_type" value="id_card" checked>
                <span><?= e(__('module.capture.doc_type_id_card')) ?></span>
            </label>
            <label class="choice">
                <input type="radio" name="document_type" value="passport">
                <span><?= e(__('module.capture.doc_type_passport')) ?></span>
            </label>
        </fieldset>
        <button type="submit" class="button button-block"><?= e(__('module.capture.continue')) ?></button>
    </form>

    <div class="capture-panel" data-panel="document" hidden>
        <h2 data-doc-title tabindex="-1"></h2>
        <p class="muted" data-doc-help></p>
        <div class="camera camera-document" data-camera hidden>
            <video playsinline muted aria-label="<?= e(__('module.capture.video_label')) ?>"></video>
            <div class="camera-guide camera-guide-card" aria-hidden="true"></div>
        </div>
        <p class="muted" data-camera-status role="status"></p>
        <div class="capture-actions" data-shoot-actions>
            <button type="button" class="button" data-action="shoot" hidden><?= e(__('module.capture.take_photo')) ?></button>
            <button type="button" class="button button-secondary" data-action="upload"><?= e(__('module.capture.upload')) ?></button>
            <input type="file" accept="image/jpeg,image/png,image/*" data-file hidden aria-label="<?= e(__('module.capture.upload_label')) ?>">
        </div>
        <div class="capture-preview" data-preview hidden>
            <img alt="<?= e(__('module.capture.preview_alt')) ?>" data-preview-img>
            <p data-quality role="status"></p>
            <div class="capture-actions">
                <button type="button" class="button button-secondary" data-action="retake"><?= e(__('module.capture.retake')) ?></button>
                <button type="button" class="button" data-action="use"><?= e(__('module.capture.use_photo')) ?></button>
            </div>
        </div>
    </div>

    <div class="capture-panel" data-panel="selfie" hidden>
        <h2 tabindex="-1"><?= e(__('module.capture.selfie_title')) ?></h2>
        <p class="muted"><?= e(__('module.capture.selfie_help', ['count' => (int) $settings['steps']])) ?></p>
        <p class="muted"><?= e(__('module.capture.selfie_live_only')) ?></p>
        <div class="camera camera-selfie" data-camera hidden>
            <video playsinline muted class="mirrored" aria-label="<?= e(__('module.capture.video_label')) ?>"></video>
            <div class="camera-guide camera-guide-face" aria-hidden="true"></div>
            <p class="challenge-banner" data-challenge aria-hidden="true" hidden></p>
        </div>
        <p class="muted" data-camera-status role="status"></p>
        <p class="capture-progress" data-progress aria-hidden="true"></p>
        <button type="button" class="button button-block" data-action="start-selfie" hidden><?= e(__('module.capture.start_selfie')) ?></button>
    </div>

    <div class="capture-panel capture-sending" data-panel="sending" hidden>
        <div class="spinner" aria-hidden="true"></div>
        <p><?= e(__('module.capture.sending')) ?></p>
    </div>

    <p class="actions-center"><a href="<?= e($sessionHref . ($query === '' ? '?' : '&') . 'shared=declined') ?>"><?= e(__('module.biometric.other_method')) ?></a></p>
</section>
