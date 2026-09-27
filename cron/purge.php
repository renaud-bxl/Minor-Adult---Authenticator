<?php

declare(strict_types=1);

/*
 * Purge planifiée des données (RGPD : minimisation, durées de conservation). Voir App\Services\Purger.
 * Cron (deploy/crontab) : toutes les heures.   Usage : php cron/purge.php
 */

use App\Core\Logger;
use App\Services\Purger;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $counts = Purger::fromApplication($app)->run();
    $app->logger()->purgeExpired();
    $app->logger()->info('purge_done', $counts);
    foreach ($counts as $name => $count) {
        echo str_pad($name, 30) . $count . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $app->logger()->error('purge_failed', Logger::exceptionContext($e));
    fwrite(STDERR, 'Échec de la purge : ' . $e::class . "\n");
    exit(1);
}
