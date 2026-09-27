-- Résultat de vérification d'âge, un par adresse, par projet et par mode (test / live).
-- Seules données conservées (CLAUDE.md) : hash d'e-mail salé par projet, e-mail chiffré, booléen,
-- âge minimal évalué, méthode, dates. shared_hash (HMAC global, sans sel de projet) n'existe que si
-- l'utilisateur a expressément accepté la réutilisation de sa vérification sur d'autres sites.
CREATE TABLE verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    livemode TINYINT(1) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    email_enc VARBINARY(1024) NOT NULL,
    is_adult TINYINT(1) NOT NULL,
    min_age TINYINT UNSIGNED NOT NULL,
    method VARCHAR(32) NOT NULL,
    source ENUM('verification', 'shared') NOT NULL DEFAULT 'verification',
    shared_hash CHAR(64) NULL,
    verified_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verifications_email (project_id, livemode, email_hash),
    KEY idx_verifications_shared (shared_hash, livemode, expires_at),
    KEY idx_verifications_expires (expires_at),
    CONSTRAINT fk_verifications_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
