-- File durable des webhooks (source de vérité ; Redis ne sert qu'à réveiller le worker).
-- payload_enc : corps JSON chiffré (il contient l'e-mail) ; session_public_id permet de l'effacer avec la
-- session (droit à l'effacement). Idempotence : un événement (event_id)
-- n'est livré qu'une fois par endpoint ; la réservation (lock_token, locked_until) empêche deux
-- workers de traiter la même livraison. Relances exponentielles via next_attempt_at.
CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(40) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    session_public_id VARCHAR(40) NULL,
    payload_enc MEDIUMBLOB NOT NULL,
    status ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    lock_token BINARY(16) NULL,
    last_status_code SMALLINT UNSIGNED NULL,
    last_error VARCHAR(64) NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_deliveries_event (endpoint_id, event_id),
    KEY idx_webhook_deliveries_due (status, next_attempt_at),
    KEY idx_webhook_deliveries_lock (lock_token),
    KEY idx_webhook_deliveries_project (project_id, created_at),
    KEY idx_webhook_deliveries_session (session_public_id),
    CONSTRAINT fk_webhook_deliveries_endpoint FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints (id) ON DELETE CASCADE,
    CONSTRAINT fk_webhook_deliveries_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
