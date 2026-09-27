<?php

declare(strict_types=1);

use App\Controllers\Api\I18nController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\PasswordResetController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\SetLocale;
use App\Middleware\StartSession;
use App\Middleware\VerifyCsrfToken;

return static function (Router $router): void {
    // Site (vitrine + espace client) : session, langue préfixée dans l'URL, CSRF.
    $router->group('', [StartSession::class], static function (Router $router): void {
        $router->get('/', [HomeController::class, 'root']);

        $router->group('/{lang:[a-z][a-z]}', [SetLocale::class, VerifyCsrfToken::class], static function (Router $router): void {
            $router->get('/', [HomeController::class, 'index']);

            $router->group('', [RedirectIfAuthenticated::class], static function (Router $router): void {
                $router->get('/register', [AuthController::class, 'showRegister']);
                $router->post('/register', [AuthController::class, 'register']);
                $router->get('/login', [AuthController::class, 'showLogin']);
                $router->post('/login', [AuthController::class, 'login']);
                $router->get('/forgot-password', [PasswordResetController::class, 'showForgot']);
                $router->post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
            });

            $router->get('/verify-email', [AuthController::class, 'verifyEmail']);
            $router->get('/reset-password', [PasswordResetController::class, 'showReset']);
            $router->post('/reset-password', [PasswordResetController::class, 'reset']);

            $router->group('', [Authenticate::class], static function (Router $router): void {
                $router->get('/dashboard', [DashboardController::class, 'index']);
                $router->post('/logout', [AuthController::class, 'logout']);
            });
        });
    });

    // API publique (sans session ni cookie).
    $router->group('/api/v1', [], static function (Router $router): void {
        $router->get('/i18n/{code:[a-z][a-z]}', [I18nController::class, 'show']);
    });
};
