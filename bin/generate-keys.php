<?php

declare(strict_types=1);

/*
 * Génère des clés aléatoires pour le .env (32 octets, base64).
 * Usage : php bin/generate-keys.php
 */

foreach (['APP_KEY', 'CRYPTO_KEY'] as $name) {
    echo $name . '=base64:' . base64_encode(random_bytes(32)) . "\n";
}
