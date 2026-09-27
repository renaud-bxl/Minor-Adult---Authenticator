-- Journal d'audit du module : projet concerné et métadonnées sans donnée d'identité
-- (identifiant de session, mode, méthode, résultat, motif d'échec, 4 derniers caractères de clé).
ALTER TABLE audit_log
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN metadata JSON NULL AFTER ip_truncated,
    ADD KEY idx_audit_log_project_created (project_id, created_at),
    ADD CONSTRAINT fk_audit_log_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL;
