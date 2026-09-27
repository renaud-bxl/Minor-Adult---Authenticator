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
 * fichiers de public/assets). Le cadrage est interdit par défaut (frame-ancestors 'none' +
 * X-Frame-Options DENY).
 *
 * Exceptions, décidées par le contrôleur via des attributs de requête :
 * - « security.frame_ancestors » (liste d'origines) : page de vérification hébergée, intégrable
 *   uniquement par les domaines autorisés du projet (CSP frame-ancestors, sans X-Frame-Options),
 *   ouvrable en popup (COOP unsafe-none : window.opener doit rester joignable par postMessage) ;
 * - « security.csp_sources » (directive => sources) : sources supplémentaires (page de démonstration
 *   qui charge le widget depuis l'hôte du module).
 */
final class SecurityHeaders implements MiddlewareInterface
{
    private const DIRECTIVES = [
        'default-src' => ["'none'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'none'"],
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $directives = self::DIRECTIVES;
        $extra = $request->attribute('security.csp_sources');
        foreach (is_array($extra) ? $extra : [] as $directive => $sources) {
            $directives[$directive] = [...($directives[$directive] ?? []), ...$sources];
        }
        $frameAncestors = $request->attribute('security.frame_ancestors');
        $embeddable = is_array($frameAncestors);
        if ($embeddable) {
            $directives['frame-ancestors'] = ["'self'", ...array_values(array_filter($frameAncestors, 'is_string'))];
        }

        $headers = [
            'Content-Security-Policy' => self::csp($directives),
            'Strict-Transport-Security' => 'max-age=' . (int) $this->app->config->get('security.hsts_max_age')
                . ($this->app->config->get('security.hsts_include_subdomains') === true ? '; includeSubDomains' : ''),
            'X-Content-Type-Options' => 'nosniff',
            // Aucun Referer : les URL de jetons (validation, réinitialisation, session) ne fuient jamais.
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => $embeddable
                ? 'camera=(self), fullscreen=(self), microphone=(), geolocation=(), payment=(), usb=()'
                : 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => $embeddable ? 'unsafe-none' : 'same-origin',
            'Cross-Origin-Resource-Policy' => $embeddable ? 'cross-origin' : 'same-origin',
        ];
        if (!$embeddable) {
            $headers['X-Frame-Options'] = 'DENY';
        }
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

    /** @param array<string, list<string>> $directives */
    private static function csp(array $directives): string
    {
        $parts = [];
        foreach ($directives as $name => $sources) {
            // Défense en profondeur : une source ne peut ni séparer des directives, ni en injecter.
            $sources = array_filter($sources, static fn (mixed $s): bool => is_string($s) && preg_match('/^[^\s;,]+$/D', $s) === 1);
            $parts[] = $name . ' ' . implode(' ', array_unique($sources));
        }

        return implode('; ', $parts);
    }
}
