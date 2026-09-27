-- Rotation d'un secret de signature avec période de recouvrement : l'ancien secret (chiffré, lié au
-- projet et au mode) reste utilisé EN PLUS du nouveau jusqu'à previous_secret_*_until. Les webhooks
-- portent alors deux signatures v1 : le client peut basculer sur le nouveau secret sans perte.
ALTER TABLE projects
    ADD COLUMN previous_secret_test_enc VARBINARY(255) NULL AFTER signing_secret_live_enc,
    ADD COLUMN previous_secret_test_until DATETIME NULL AFTER previous_secret_test_enc,
    ADD COLUMN previous_secret_live_enc VARBINARY(255) NULL AFTER previous_secret_test_until,
    ADD COLUMN previous_secret_live_until DATETIME NULL AFTER previous_secret_live_enc;
