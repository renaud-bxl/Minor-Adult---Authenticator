<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\ConfigValidator;
use App\Core\ErrorHandler;

/*
 * Amorçage commun (web, CLI) : autoload, .env, configuration, fuseau horaire, gestion des erreurs,
 * contrôle de la configuration en production (refus de démarrer si elle est incomplète).
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::boot(dirname(__DIR__));
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ErrorHandler::register($app->logger());

if ($app->config->get('app.env') === 'production') {
    $problems = ConfigValidator::productionProblems($app->config, PHP_SAPI !== 'cli');
    if ($problems !== []) {
        throw new RuntimeException('Configuration de production invalide : ' . implode(' ; ', $problems));
    }
}

return $app;
