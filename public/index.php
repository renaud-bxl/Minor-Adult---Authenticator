<?php

declare(strict_types=1);

use App\Core\Kernel;
use App\Core\Request;

// Serveur de développement (php -S) : les fichiers statiques existants sont servis directement.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file) && !str_contains($file, '..')) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals();
$response = (new Kernel($app))->handle($request);
$response->send($request->method() !== 'HEAD');
