-- Journal d'audit : qui (identifiants internes), quoi, quand, depuis quel bloc d'adresses.
-- Aucune donnée d'identité : pas d'e-mail, pas d'IP complète (tronquée en /24 ou /48).
CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    ip_truncated VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_log_account_created (account_id, created_at),
    KEY idx_audit_log_user_created (user_id, created_at),
    CONSTRAINT fk_audit_log_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
