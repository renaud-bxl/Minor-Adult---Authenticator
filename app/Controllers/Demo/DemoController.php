<?php

declare(strict_types=1);

namespace App\Controllers\Demo;

use App\Controllers\Controller;
use App\Core\HostMap;
use App\Core\HttpException;
use App\Core\IpAddress;
use App\Core\Request;
use App\Core\Response;
use App\Models\VerificationSession;
use App\Verification\PageToken;
use App\Verification\ReturnToken;
use App\Verification\Webhooks\WebhookSignature;

/**
 * Démonstration : « plateforme cliente fictive » (boutique en ligne) qui intègre le module comme le
 * ferait un vrai client, sur un autre hôte (DEMO_URL) :
 * - son serveur crée une session sandbox par l'API publique (clé sk_test_ du projet de démo) ;
 * - sa page ouvre le widget (modale, popup, iframe ou redirection) ;
 * - il reçoit le webhook signé (vérifié : signature + anti-rejeu + déduplication), le jeton de retour
 *   (JWT vérifié) et confirme le résultat par GET /api/v1/verifications.
 *
 * Disponible hors production, ou avec DEMO_ENABLED=true (route liée à l'hôte « demo », voir HostMap).
 * Les données de démonstration sont conservées une heure dans Redis (e-mail chiffré).
 */
final class DemoController extends Controller
{
    private const TTL = 3600;
    private const MODES = ['modal', 'popup', 'iframe', 'redirect'];

    public function index(Request $request): Response
    {
        $locale = $this->locale($request);
        $verifyUrl = $this->verifyUrl();
        $verifyOrigin = (string) HostMap::origin($verifyUrl);
        // La page charge le widget depuis l'hôte du module : sources ajoutées à la CSP stricte.
        $request->setAttribute('security.csp_sources', [
            'script-src' => [$verifyOrigin],
            'connect-src' => [$verifyOrigin],
            'frame-src' => [$verifyOrigin],
        ]);

        $response = Response::html($this->app->view()->render('demo/index', [
            'pageTitle' => __('site.demo.title'),
            'configured' => $this->apiKey() !== '',
            'widgetUrl' => $verifyUrl . '/widget/verify.js',
            'token' => $this->token()->issue('demo', time()),
            'lang' => $locale,
            'languages' => $this->app->translator()->enabled(),
            'defaultEmail' => 'client+' . strtolower(bin2hex(random_bytes(3))) . '@example.com',
            'price' => $this->app->formatter()->currency(2490, 'EUR', $locale),
        ], 'layouts/demo'));
        // Ce qu'un client doit aussi faire pour intégrer le widget : autoriser la caméra pour l'origine
        // du module (iframe, phase 3) et garder le lien avec le popup (COOP same-origin le couperait).
        $response->setHeader('Permissions-Policy', 'camera=(self "' . $verifyOrigin . '"), fullscreen=(self "' . $verifyOrigin . '"), microphone=(), geolocation=(), payment=(), usb=()');
        $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        return $response;
    }

    /** POST /demo/sessions (JSON) : le « serveur du client » crée une session sandbox par l'API. */
    public function createSession(Request $request): Response
    {
        $this->guard($request);
        [$max, $window] = $this->app->rateLimit('demo_session_ip');
        if (!$this->app->rateLimiter()->attempt('demo_session_ip', IpAddress::rateLimitKey($request->ip()), $max, $window)->allowed) {
            return Response::json(['error' => 'rate_limited'], 429);
        }
        $input = $request->json();
        $mode = in_array($input['mode'] ?? null, self::MODES, true) ? (string) $input['mode'] : 'modal';
        $body = array_filter([
            'email' => is_string($input['email'] ?? null) ? $input['email'] : '',
            'min_age' => is_int($input['min_age'] ?? null) ? $input['min_age'] : 18,
            'lang' => $this->locale($request),
            'return_url' => $this->demoUrl() . '/demo/return',
            'external_ref' => 'demo_' . bin2hex(random_bytes(4)),
        ], static fn (mixed $v): bool => $v !== '');

        [$status, $response] = $this->callApi('POST', '/api/v1/sessions', $body);
        // Identifiant du « client » de la boutique (cookie propre au site de démo) : la session VeriAge
        // lui est rattachée dès sa création, et le retour n'est accepté que pour lui.
        $owner = $this->owner($request) ?? bin2hex(random_bytes(16));
        if (($status === 200 || $status === 201) && is_string($response['session_id'] ?? null)) {
            $this->remember((string) $response['session_id'], (string) $body['email'], (string) ($response['project'] ?? ''), $owner);
        }

        $result = Response::json(['mode' => $mode, 'api_status' => $status, 'api_request' => $body, 'api_response' => $response], $status === 0 ? 502 : 200);
        $secure = str_starts_with($this->demoUrl(), 'https://') ? '; Secure' : '';
        $result->addCookie('demo_owner=' . $owner . '; Path=/demo; Max-Age=' . self::TTL . '; HttpOnly; SameSite=Lax' . $secure);

        return $result;
    }

    /** GET /demo/status?session_id= : confirmation serveur (API) et webhooks reçus. */
    public function status(Request $request): Response
    {
        $this->guard($request);
        $sessionId = $request->query('session_id');
        $known = $this->recall($sessionId);
        if ($known === null) {
            return Response::json(['error' => 'unknown_session'], 404);
        }
        [$status, $response] = $this->callApi('GET', '/api/v1/verifications?' . http_build_query(['email' => $known['email']], '', '&', PHP_QUERY_RFC3986));

        return Response::json(['api_status' => $status, 'api_response' => $response, 'webhooks' => $this->webhooks($sessionId)]);
    }

    /** GET /demo/return?session_id=&token= : retour du mode redirection, jeton JWT vérifié. */
    public function returned(Request $request): Response
    {
        $sessionId = $request->query('session_id');
        $known = $this->recall($sessionId);
        $claims = $known === null || $this->signingSecret() === ''
            ? null
            : ReturnToken::verify($request->query('token'), $this->signingSecret(), $known['project'], $sessionId);
        // Implémentation de référence côté client (voir docs/integration.md) :
        // 1. la session doit appartenir à l'utilisateur qui l'a créée (ici, cookie de la boutique) ;
        // 2. chaque jeton (« jti ») n'est accepté qu'une seule fois.
        $problem = null;
        if ($claims !== null && ($known['owner'] === '' || !hash_equals($known['owner'], hash('sha256', (string) $this->owner($request))))) {
            [$claims, $problem] = [null, 'owner_mismatch'];
        }
        if ($claims !== null) {
            $ttl = max(1, (int) ($claims['exp'] ?? 0) - time()) + 60;
            if ($this->app->redis()->set('demo:jti:' . hash('sha256', (string) ($claims['jti'] ?? '')), '1', 'EX', $ttl, 'NX') === null) {
                [$claims, $problem] = [null, 'token_replayed'];
            }
        }
        $api = null;
        if ($known !== null) {
            [, $api] = $this->callApi('GET', '/api/v1/verifications?' . http_build_query(['email' => $known['email']], '', '&', PHP_QUERY_RFC3986));
        }
        $this->locale($request);

        return Response::html($this->app->view()->render('demo/return', [
            'pageTitle' => __('site.demo.return_title'),
            'sessionId' => $sessionId,
            'claims' => $claims,
            'problem' => $problem,
            'api' => $api,
            'webhooks' => $known === null ? [] : $this->webhooks($sessionId),
        ], 'layouts/demo'));
    }

    /**
     * POST /demo/webhook : réception d'un webhook, comme doit le faire un client : signature HMAC
     * (temps constant) et horodatage dans la fenêtre de 5 min, puis déduplication par identifiant.
     */
    public function webhook(Request $request): Response
    {
        $payload = $request->rawBody();
        $valid = WebhookSignature::verify($payload, (string) $request->header(WebhookSignature::HEADER), $this->signingSecret(), time());
        if (!$valid) {
            $this->app->logger()->warning('demo_webhook_rejected', []);

            return Response::json(['error' => 'invalid_signature'], 400);
        }
        $event = json_decode($payload, true);
        $sessionId = is_array($event) ? (string) ($event['data']['session_id'] ?? '') : '';
        $eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
        if ($sessionId === '' || $eventId === '') {
            return Response::json(['error' => 'invalid_payload'], 400);
        }
        $redis = $this->app->redis();
        // Déduplication : une relance du même événement est acquittée sans être retraitée.
        if ($redis->set('demo:event:' . $eventId, '1', 'EX', self::TTL, 'NX') === null) {
            return Response::json(['received' => true, 'duplicate' => true]);
        }
        $key = 'demo:webhooks:' . hash('sha256', $sessionId);
        $redis->rpush($key, [json_encode([
            'id' => $eventId,
            'type' => (string) ($event['type'] ?? ''),
            'status' => $event['data']['status'] ?? null,
            'is_adult' => $event['data']['is_adult'] ?? null,
            'method' => $event['data']['method'] ?? null,
            'attempt' => (int) $request->header('X-VeriAge-Delivery-Attempt'),
            'received_at' => gmdate('c'),
            'signature' => 'valid',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        $redis->expire($key, self::TTL);

        return Response::json(['received' => true]);
    }

    /**
     * Appel de l'API publique du module, comme le ferait le serveur d'un client.
     *
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>|null} statut HTTP (0 : injoignable) et réponse JSON
     */
    private function callApi(string $method, string $path, ?array $body = null): array
    {
        $handle = curl_init($this->verifyUrl() . $path);
        $headers = ['Authorization: Bearer ' . $this->apiKey(), 'Accept: application/json', 'Accept-Language: ' . locale()];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [$status, is_array($decoded) ? $decoded : null];
    }

    /** Jeton signé de la page (les appels JSON de la page sont refusés sans lui : pas de CSRF). */
    private function guard(Request $request): void
    {
        if (!$this->token()->validate((string) $request->header('X-Demo-Token'), 'demo', time())) {
            throw new HttpException(419);
        }
    }

    /** Identifiant du visiteur de la boutique (cookie HttpOnly du site de démo), ou null. */
    private function owner(Request $request): ?string
    {
        $owner = (string) $request->cookie('demo_owner');

        return preg_match('/^[a-f0-9]{32}$/D', $owner) === 1 ? $owner : null;
    }

    private function remember(string $sessionId, string $email, string $project, string $owner): void
    {
        $payload = json_encode(['email' => $email, 'project' => $project, 'owner' => hash('sha256', $owner)], JSON_THROW_ON_ERROR);
        $this->app->redis()->setex(
            'demo:session:' . hash('sha256', $sessionId),
            self::TTL,
            base64_encode($this->app->crypto()->encrypt($payload, 'demo:' . $sessionId)),
        );
    }

    /** @return array{email: string, project: string, owner: string}|null */
    private function recall(string $sessionId): ?array
    {
        if (preg_match(VerificationSession::ID_REGEX, $sessionId) !== 1) {
            return null;
        }
        $stored = $this->app->redis()->get('demo:session:' . hash('sha256', $sessionId));
        if (!is_string($stored)) {
            return null;
        }
        try {
            $data = json_decode($this->app->crypto()->decrypt((string) base64_decode($stored, true), 'demo:' . $sessionId), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? ['email' => (string) $data['email'], 'project' => (string) $data['project'], 'owner' => (string) ($data['owner'] ?? '')] : null;
    }

    /** @return list<array<string, mixed>> */
    private function webhooks(string $sessionId): array
    {
        $items = $this->app->redis()->lrange('demo:webhooks:' . hash('sha256', $sessionId), 0, 20);

        return array_values(array_filter(array_map(static fn (string $i): mixed => json_decode($i, true), $items), 'is_array'));
    }

    private function locale(Request $request): string
    {
        $translator = $this->app->translator();
        $locale = $translator->isEnabled($request->query('lang'))
            ? $request->query('lang')
            : $this->app->negotiator()->negotiate(acceptLanguage: $request->header('Accept-Language'));
        $translator->setLocale($locale);

        return $locale;
    }

    private function token(): PageToken
    {
        return new PageToken($this->app->crypto()->deriveKey('demo-page'), 7200);
    }

    private function apiKey(): string
    {
        return (string) $this->app->config->get('app.demo_api_key');
    }

    private function signingSecret(): string
    {
        return (string) $this->app->config->get('app.demo_signing_secret');
    }

    private function verifyUrl(): string
    {
        return (string) ($this->app->config->get('app.verify_url') ?: $this->app->config->get('app.url'));
    }

    private function demoUrl(): string
    {
        return (string) $this->app->config->get('app.demo_url');
    }
}
