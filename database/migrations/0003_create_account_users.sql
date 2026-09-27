-- Rattachement des utilisateurs aux comptes, avec leur rôle.
CREATE TABLE account_users (
    account_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'developer', 'accountant') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (account_id, user_id),
    KEY idx_account_users_user (user_id),
    CONSTRAINT fk_account_users_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_account_users_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
