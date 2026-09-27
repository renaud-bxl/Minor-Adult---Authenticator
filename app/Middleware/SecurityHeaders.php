<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;

/**
 * En-têtes de sécurité appliqués à toutes les réponses dynamiques.
 *
 * CSP stricte : aucune source externe, aucun script ni style inline (le JS et le CSS sont des
 * fichiers de public/assets). Le cadrage est interdit (frame-ancestors 'none' + X-Frame-Options) ;
 * la page de vérification intégrable (phase 2) aura sa propre politique frame-ancestors par client.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'";

    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => self::CSP,
            'Strict-Transport-Security' => 'max-age=' . (int) $this->app->config->get('security.hsts_max_age') . '; includeSubDomains',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // Aucun Referer : les URL de jetons (validation, réinitialisation) ne fuient jamais.
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response->setHeader($name, $value);
            }
        }
        // Les pages HTML contiennent des jetons CSRF ou des données de compte : jamais en cache partagé.
        if (!$response->hasHeader('Cache-Control')) {
            $response->setHeader('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
