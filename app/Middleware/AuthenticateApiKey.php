<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Application;
use App\Core\IpAddress;
use App\Core\Request;
use App\Core\Response;
use App\Models\ApiKeyRepository;
use App\Verification\ApiContext;

/**
 * Authentification de l'API par clé secrète (« Authorization: Bearer sk_live_… | sk_test_… ») et
 * limitation de débit :
 * - par IP, avant toute authentification (protège la base) ;
 * - échecs d'authentification par IP (recherche de clés) : 429 au-delà du seuil ;
 * - par clé, après authentification (en-têtes X-RateLimit-*).
 * La clé n'est jamais journalisée ; seul son SHA-256 est comparé en base.
 */
final class AuthenticateApiKey implements MiddlewareInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $ipKey = IpAddress::rateLimitKey($request->ip());
        $this->throttle('api_ip', $ipKey);

        $header = (string) $request->header('Authorization');
        $key = preg_match('/^Bearer\s+(\S+)$/D', $header, $m) === 1 ? $m[1] : '';
        $repository = new ApiKeyRepository($this->app->db());
        $auth = $key === '' ? null : $repository->authenticate($key);
        if ($auth === null) {
            [$max, $window] = $this->app->rateLimit('api_auth_failure_ip');
            $failures = $this->app->rateLimiter()->attempt('api_auth_failure_ip', $ipKey, $max, $window);
            $this->app->logger()->info('api_auth_failed', ['reason' => $key === '' ? 'missing' : 'invalid']);
            if (!$failures->allowed) {
                throw new ApiException(429, 'rate_limited', [], ['Retry-After' => (string) $failures->retryAfter]);
            }
            throw new ApiException(401, 'unauthorized', [], ['WWW-Authenticate' => 'Bearer realm="VeriAge"']);
        }

        [$max, $window] = $this->app->rateLimit('api_key');
        $limit = $this->app->rateLimiter()->attempt('api_key', (string) $auth['key_id'], $max, $window);
        if (!$limit->allowed) {
            throw new ApiException(429, 'rate_limited', [], ['Retry-After' => (string) $limit->retryAfter]);
        }
        $repository->touch($auth['key_id']);
        $request->setAttribute('api', new ApiContext($auth['project'], $auth['livemode'], $auth['key_id'], $auth['last4']));

        $response = $next($request);
        $response->setHeader('X-RateLimit-Limit', (string) $max);
        $response->setHeader('X-RateLimit-Remaining', (string) $limit->remaining);

        return $response;
    }

    private function throttle(string $bucket, string $identifier): void
    {
        [$max, $window] = $this->app->rateLimit($bucket);
        $limit = $this->app->rateLimiter()->attempt($bucket, $identifier, $max, $window);
        if (!$limit->allowed) {
            throw new ApiException(429, 'rate_limited', [], ['Retry-After' => (string) $limit->retryAfter]);
        }
    }
}
