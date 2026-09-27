-- Preuve de contrôle de l'adresse : code à 6 chiffres (par défaut) ou lien à usage unique (forte
-- entropie), exigé quand une adresse cumule trop de codes erronés tous clients confondus.
ALTER TABLE verification_sessions ADD COLUMN proof_kind ENUM('code', 'link') NOT NULL DEFAULT 'code' AFTER code_sent_at;
