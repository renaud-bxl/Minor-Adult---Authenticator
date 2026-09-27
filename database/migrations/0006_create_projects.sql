-- Projet d'un compte client = une plateforme intégrant le module (clés API, domaines, réglages).
-- email_salt : sel propre au projet pour le hash des e-mails (aucun rapprochement entre clients).
-- signing_secret_*_enc : secrets de signature (webhooks HMAC et jeton de retour JWT), chiffrés
-- en AES-256-GCM (ils doivent être relus pour signer ; les clés API, elles, ne sont que hachées).
CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    min_age TINYINT UNSIGNED NOT NULL DEFAULT 18,
    validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
    allowed_origins JSON NOT NULL,
    methods JSON NOT NULL,
    accept_shared TINYINT(1) NOT NULL DEFAULT 0,
    email_salt BINARY(32) NOT NULL,
    signing_secret_test_enc VARBINARY(255) NOT NULL,
    signing_secret_live_enc VARBINARY(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_public_id (public_id),
    KEY idx_projects_account (account_id),
    CONSTRAINT fk_projects_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
