<?php

declare(strict_types=1);

/*
 * Rotation de CRYPTO_KEY : réécrit avec la clé courante les chiffrés produits avec une ancienne clé
 * (voir App\Services\KeyRotation pour la procédure complète). Idempotent.
 *
 *   php bin/reencrypt.php
 */

use App\Services\KeyRotation;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    foreach ((new KeyRotation($app->db(), $app->crypto()))->run() as $column => $count) {
        echo str_pad($column, 40) . $count . "\n";
    }
} catch (RuntimeException $e) {
    // Chiffré illisible : clé absente du trousseau (CRYPTO_PREVIOUS_KEYS incomplet) ou donnée altérée.
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . " Vérifiez CRYPTO_PREVIOUS_KEYS.\n");
    exit(1);
}
exit(0);
