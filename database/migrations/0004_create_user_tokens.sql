-- Jetons envoyés par e-mail (validation d'adresse, réinitialisation du mot de passe).
-- Seul le SHA-256 du jeton est stocké ; un jeton est à usage unique (used_at) et expire.
CREATE TABLE user_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('email_verification', 'password_reset') NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_tokens_hash (token_hash),
    KEY idx_user_tokens_user_type (user_id, type),
    CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
