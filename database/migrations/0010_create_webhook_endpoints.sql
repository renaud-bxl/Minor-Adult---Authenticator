-- URL de réception des webhooks d'un projet, par mode. Validées contre la SSRF à l'enregistrement
-- et à chaque envoi (https, domaines autorisés du projet, aucune adresse privée ou réservée).
CREATE TABLE webhook_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    livemode TINYINT(1) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_endpoints_public_id (public_id),
    KEY idx_webhook_endpoints_project (project_id, livemode),
    CONSTRAINT fk_webhook_endpoints_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
