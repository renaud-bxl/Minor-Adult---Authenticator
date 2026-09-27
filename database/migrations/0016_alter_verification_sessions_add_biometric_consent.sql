-- Consentement explicite (RGPD art. 9) au traitement des données BIOMÉTRIQUES, recueilli sur un écran
-- dédié juste avant la capture du document et du visage (méthode « id_document_face »). Horodatage seul.
ALTER TABLE verification_sessions ADD COLUMN biometric_consent_at DATETIME NULL AFTER consent_at;
