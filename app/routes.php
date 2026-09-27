<?php

declare(strict_types=1);

use App\Controllers\Api\I18nController;
use App\Controllers\Api\SessionController;
use App\Controllers\Api\VerificationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\Demo\DemoController;
use App\Controllers\HomeController;
use App\Controllers\PasswordResetController;
use App\Controllers\Verify\HostedPageController;
use App\Core\HostMap;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\AuthenticateApiKey;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\SetLocale;
use App\Middleware\StartSession;
use App\Middleware\ThrottleVerifyPage;
use App\Middleware\VerifyCsrfToken;
use App\Models\VerificationSession;

return static function (Router $router): void {
    // Site (vitrine + espace client, hôte www.) : session, langue préfixée dans l'URL, CSRF.
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
    }, HostMap::SITE);

    // Module de vérification (hôte verify.) : API, page hébergée, sans session ni cookie.
    $router->group('', [], static function (Router $router): void {
        $router->group('/api/v1', [], static function (Router $router): void {
            // Public (widget) : traductions du module.
            $router->get('/i18n/{code:[a-z][a-z]}', [I18nController::class, 'show']);

            // Serveur à serveur : clé secrète obligatoire.
            $router->group('', [AuthenticateApiKey::class], static function (Router $router): void {
                $router->post('/sessions', [SessionController::class, 'store']);
                $router->get('/verifications', [VerificationController::class, 'show']);
                $router->post('/verifications/lookup', [VerificationController::class, 'lookup']);
                $router->delete('/verifications', [VerificationController::class, 'destroy']);
            });
        });

        $router->group('/s/{session:' . VerificationSession::ROUTE_PATTERN . '}', [ThrottleVerifyPage::class], static function (Router $router): void {
            $router->get('/', [HostedPageController::class, 'show']);
            $router->get('/return', [HostedPageController::class, 'returnToClient']);
            $router->get('/confirm', [HostedPageController::class, 'confirm']);
            $router->post('/consent', [HostedPageController::class, 'consent']);
            $router->post('/code', [HostedPageController::class, 'code']);
            $router->post('/code/resend', [HostedPageController::class, 'resendCode']);
            $router->post('/shared', [HostedPageController::class, 'shared']);
            $router->post('/method/{method:[a-z_]+}', [HostedPageController::class, 'method']);
        });
    }, HostMap::VERIFY);

    // Démonstration « plateforme cliente fictive » (hôte DEMO_URL, jamais en production sauf DEMO_ENABLED).
    $router->group('/demo', [], static function (Router $router): void {
        $router->get('/', [DemoController::class, 'index']);
        $router->post('/sessions', [DemoController::class, 'createSession']);
        $router->get('/status', [DemoController::class, 'status']);
        $router->get('/return', [DemoController::class, 'returned']);
        $router->post('/webhook', [DemoController::class, 'webhook']);
    }, HostMap::DEMO);
};
