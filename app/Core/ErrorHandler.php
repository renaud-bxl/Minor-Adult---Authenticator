<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Filet de sécurité global : les erreurs PHP deviennent des exceptions, et toute exception non
 * interceptée (hors Kernel, qui gère les siennes) produit une page 500 générique et une trace
 * détaillée dans le journal, sans donnée personnelle.
 */
final class ErrorHandler
{
    public static function register(Logger $logger): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($logger): void {
            $logger->error('unhandled_exception', Logger::exceptionContext($e));
            self::emitGenericFailure();
        });

        register_shutdown_function(static function () use ($logger): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $logger->error('fatal_error', ['message' => $error['message'], 'file' => $error['file'] . ':' . $error['line']]);
            }
        });
    }

    private static function emitGenericFailure(): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Erreur interne : consulter storage/logs.\n");
            // Un gestionnaire d'exceptions personnalisé ferait sinon sortir le script avec le code 0.
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        // Dernier recours : les traductions peuvent être indisponibles à ce stade.
        echo "500\n";
    }
}
