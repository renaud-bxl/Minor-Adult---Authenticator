<?php

declare(strict_types=1);

/*
 * Applique les migrations en attente.
 * Usage : php bin/migrate.php [--status]
 */

use App\Core\Logger;
use App\Core\Migrator;

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$migrator = new Migrator($app->db(), $app->path((string) $app->config->get('database.migrations_path')));

try {
    if (in_array('--status', $argv, true)) {
        $pending = $migrator->pending();
        echo $pending === [] ? "Aucune migration en attente.\n" : "En attente :\n  " . implode("\n  ", $pending) . "\n";
        exit(0);
    }

    $applied = $migrator->migrate();
    echo $applied === [] ? "Base à jour : aucune migration en attente.\n" : "Appliquées :\n  " . implode("\n  ", $applied) . "\n";
    exit(0);
} catch (Throwable $e) {
    $app->logger()->error('migration_failed', Logger::exceptionContext($e));
    fwrite(STDERR, 'Échec des migrations : ' . $e::class . ' : ' . $e->getMessage() . "\n");
    exit(1);
}
