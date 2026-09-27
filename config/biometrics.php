<?php

declare(strict_types=1);

// Méthode « pièce d'identité + visage » : microservice biometrics/ (FastAPI, 127.0.0.1), 100 % local.
// Seuils lus dans .env en attendant l'administration (phase 7). Contrôlés au démarrage en production
// (ConfigValidator) : une faute de frappe ne doit ni bloquer tout le monde ni désactiver un contrôle.
return [
    // Méthode proposée seulement si activée ET configurée (URL et secret).
    'enabled' => (bool) env('BIOMETRICS_ENABLED', false),
    // Boucle locale uniquement (http://127.0.0.1:port ou http://[::1]:port) : refus sinon.
    'url' => (string) env('BIOMETRICS_URL', 'http://127.0.0.1:8765'),
    // Secret partagé avec le microservice (HMAC des requêtes ET des réponses), 32 caractères au moins.
    'secret' => (string) env('BIOMETRICS_SECRET', ''),
    'connect_timeout' => 2,
    // L'analyse (OCR + ~50 images) prend quelques secondes ; au-delà, échec technique.
    'timeout' => (int) env('BIOMETRICS_TIMEOUT', 45),

    // Correspondance document ↔ selfie (similarité cosinus SFace, de -1 à 1). OpenCV recommande 0,363
    // pour deux photos d'une même personne ; la photo d'un document (impression, hologrammes, âge de la
    // photo) abaisse le score. Valeurs de départ À CALIBRER sur des données réelles avant la production.
    'face_match_threshold' => (float) env('BIOMETRICS_FACE_MATCH_THRESHOLD', 0.40),
    // Sous le seuil d'acceptation mais au-dessus de celui-ci : revue manuelle si le projet l'a choisie.
    'face_review_threshold' => (float) env('BIOMETRICS_FACE_REVIEW_THRESHOLD', 0.30),
    // Réglage par défaut des nouveaux projets sous le seuil : fail (échec) | review (revue manuelle).
    'below_threshold_default' => (string) env('BIOMETRICS_BELOW_THRESHOLD', 'fail'),
    // Délai de décision d'une revue (heures) ; au-delà, la session expire.
    'review_ttl_hours' => (int) env('BIOMETRICS_REVIEW_TTL_HOURS', 48),

    // Contrôle du vivant : seuils transmis au microservice (bornés par lui).
    'liveness' => [
        'yaw_threshold' => (float) env('BIOMETRICS_LIVENESS_YAW', 0.28),
        'neutral_max_yaw' => 0.15,
        'blink_ratio' => (float) env('BIOMETRICS_LIVENESS_BLINK_RATIO', 0.65),
        'same_face_min' => (float) env('BIOMETRICS_LIVENESS_SAME_FACE', 0.30),
        'min_frames' => 8,
        'max_frames' => 60,
        'min_step_ms' => 600,
        'replay_threshold' => (float) env('BIOMETRICS_LIVENESS_REPLAY', 30.0),
    ],

    // Défis tirés par le serveur et rythme de la capture (partagés avec le script de la page).
    'challenge' => [
        'steps' => 3,
        // Validité d'un défi tiré (secondes) et tirages autorisés par session.
        'ttl' => 300,
        'max_attempts' => 3,
        // Fenêtre initiale (regarder la caméra), puis une fenêtre par défi, images toutes les 200 ms :
        // 7 + 3 × 15 = 52 images (≤ max_frames). « Fermer les yeux une seconde » laisse ~5 images fermées.
        'neutral_ms' => 1500,
        'step_ms' => 3000,
        'frame_interval_ms' => 200,
    ],

    // Envoi des images (navigateur → PHP, chiffré AES-GCM en plus de HTTPS), bornes contrôlées avant relais.
    'capture' => [
        'max_body_bytes' => 12 * 1024 * 1024,
        'doc_max_bytes' => 3 * 1024 * 1024,
        'doc_max_side' => 4096,
        'doc_min_side' => 480,
        'frame_max_bytes' => 400 * 1024,
        'frame_max_side' => 1280,
        'max_frames' => 60,
    ],

    // Fichiers temporaires éventuels (corps de requête que PHP recopie au-delà de 16 Kio : chiffrés, voir
    // CaptureCipher) : dossier hors de public/, purgé toutes les 15 minutes (cron/purge_tmp.php).
    // En production : l'upload_tmp_dir du pool PHP-FPM de VeriAge (idéalement un tmpfs, voir README).
    'tmp_dir' => (string) env('BIOMETRICS_TMP_DIR', 'storage/tmp'),
    'tmp_max_age_minutes' => 15,
];
