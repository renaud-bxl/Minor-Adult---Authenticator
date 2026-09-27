-- Comportement du projet quand la correspondance du visage est sous le seuil (mais au-dessus du seuil de
-- revue) : échec immédiat (« fail », par défaut) ou vérification manuelle (« review »).
ALTER TABLE projects ADD COLUMN below_threshold ENUM('fail', 'review') NOT NULL DEFAULT 'fail' AFTER negative_ttl_hours;
