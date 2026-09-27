-- File de vérification manuelle (phase 3 : file sans interface ; décision en ligne de commande,
-- bin/review.php ; l'interface d'administration viendra en phase 7).
-- Aucune donnée d'identité ni aucune image : uniquement les signaux de la décision automatique (score de
-- correspondance, contrôle du vivant, motifs codés) et le résultat d'âge provisoire (booléen).
CREATE TABLE manual_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    livemode TINYINT(1) NOT NULL,
    method VARCHAR(32) NOT NULL,
    face_match_score DECIMAL(5, 4) NULL,
    liveness_passed TINYINT(1) NOT NULL,
    reasons JSON NOT NULL,
    provisional_is_adult TINYINT(1) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_manual_reviews_session (session_id),
    KEY idx_manual_reviews_status (status, created_at),
    CONSTRAINT fk_manual_reviews_session FOREIGN KEY (session_id) REFERENCES verification_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_manual_reviews_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
