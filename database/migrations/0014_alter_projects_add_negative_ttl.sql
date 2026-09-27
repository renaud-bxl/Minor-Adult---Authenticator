-- Durée de validité (heures) d'un résultat NÉGATIF (âge non atteint), réglable par projet.
-- Courte par défaut (24 h) : on ne conserve pas la date de naissance, on ne sait donc pas quand la
-- personne atteindra l'âge requis ; elle doit pouvoir se faire revérifier. 0 : jamais réutilisé.
ALTER TABLE projects ADD COLUMN negative_ttl_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER validity_days;
