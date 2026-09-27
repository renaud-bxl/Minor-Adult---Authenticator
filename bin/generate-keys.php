<?php

declare(strict_types=1);

/*
 * Génère des clés aléatoires pour le .env (32 octets, base64) et le secret du microservice biométrique.
 * Usage : php bin/generate-keys.php
 */

foreach (['APP_KEY', 'CRYPTO_KEY'] as $name) {
    echo $name . '=base64:' . base64_encode(random_bytes(32)) . "\n";
}
// Secret partagé avec le microservice biométrique (même valeur dans son EnvironmentFile systemd).
echo 'BIOMETRICS_SECRET=' . bin2hex(random_bytes(32)) . "\n";
