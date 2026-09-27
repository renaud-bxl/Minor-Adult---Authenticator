<?php

declare(strict_types=1);

/*
 * Contrôle du microservice biométrique local : php bin/biometrics.php health
 * Vérifie l'authentification mutuelle (requête et réponse signées) et l'état des modèles.
 * Code retour 0 si le service répond et que tout est chargé, 1 sinon.
 */

use App\Verification\Biometrics\BiometricsException;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$client = $app->biometricsClient();
if ($client === null) {
    fwrite(STDERR, "Biométrie désactivée ou non configurée (BIOMETRICS_ENABLED, BIOMETRICS_SECRET).\n");
    exit(1);
}
try {
    $health = $client->health();
} catch (BiometricsException $e) {
    fwrite(STDERR, 'Service injoignable ou réponse refusée : ' . $e->reason . ($e->detail !== null ? ' (' . $e->detail . ')' : '') . "\n");
    exit(1);
}
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(($health['tesseract'] ?? false) === true && ($health['landmarks'] ?? '') === 'mediapipe' ? 0 : 1);
