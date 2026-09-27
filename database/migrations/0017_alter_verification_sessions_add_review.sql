-- Vérification manuelle : la session reste « pending » (API : statut pending) jusqu'à la décision d'un
-- opérateur ou jusqu'à son expiration (expires_at est prolongé du délai de revue). Horodatage seul.
ALTER TABLE verification_sessions ADD COLUMN review_at DATETIME NULL AFTER biometric_consent_at;
