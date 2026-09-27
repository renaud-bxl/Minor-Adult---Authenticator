-- Clés API secrètes (sk_live_… / sk_test_…) : seul le SHA-256 est stocké, avec les 4 derniers
-- caractères pour permettre au client de reconnaître une clé. livemode = 0 : sandbox.
CREATE TABLE api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    livemode TINYINT(1) NOT NULL,
    key_hash BINARY(32) NOT NULL,
    last4 CHAR(4) NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (key_hash),
    KEY idx_api_keys_project (project_id, livemode),
    CONSTRAINT fk_api_keys_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
