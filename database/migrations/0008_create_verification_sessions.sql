-- Session de vérification créée par POST /api/v1/sessions, suivie sur la page hébergée /s/{public_id}.
-- E-mail : hash salé par projet (recherche) + chiffré AES-256-GCM (restitution au client). Aucune
-- donnée d'identité. Le code de contrôle de l'e-mail n'est stocké que haché (SHA-256).
-- Le résultat est recopié ici (result_*) pour le jeton de retour et les webhooks, même si la
-- vérification est ensuite effacée. Purge par cron (rétention courte).
CREATE TABLE verification_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    api_key_id BIGINT UNSIGNED NULL,
    livemode TINYINT(1) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    email_enc VARBINARY(1024) NOT NULL,
    min_age TINYINT UNSIGNED NOT NULL,
    return_url VARCHAR(2048) NULL,
    lang CHAR(2) NULL,
    external_ref VARCHAR(64) NULL,
    status ENUM('pending', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    consent_at DATETIME NULL,
    code_hash BINARY(32) NULL,
    code_expires_at DATETIME NULL,
    code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    code_sends TINYINT UNSIGNED NOT NULL DEFAULT 0,
    code_sent_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    share_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    method VARCHAR(32) NULL,
    reuse ENUM('none', 'same_client', 'shared') NOT NULL DEFAULT 'none',
    result_is_adult TINYINT(1) NULL,
    result_verified_at DATETIME NULL,
    result_expires_at DATETIME NULL,
    failure_reason VARCHAR(32) NULL,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_sessions_public_id (public_id),
    KEY idx_verification_sessions_email (project_id, livemode, email_hash, created_at),
    KEY idx_verification_sessions_created (created_at),
    CONSTRAINT fk_verification_sessions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_verification_sessions_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
