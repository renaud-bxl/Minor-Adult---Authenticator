-- Revue manuelle sans image retirée de la V1 (audit de la phase 3, E3) : tout projet réglé sur « review »
-- repasse en « fail » (sous le seuil, la vérification échoue). La colonne et la table restent, inutilisées,
-- pour une réactivation éventuelle après décision juridique (conservation chiffrée des images).
UPDATE projects SET below_threshold = 'fail' WHERE below_threshold = 'review';
