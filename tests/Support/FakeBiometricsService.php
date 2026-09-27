<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Verification\Biometrics\BiometricsException;
use App\Verification\Biometrics\BiometricsTransport;

/**
 * Faux microservice biométrique (transport en mémoire) : vérifie la signature des requêtes EXACTEMENT
 * comme le vrai service (fenêtre d'horodatage, nonce unique), mémorise la dernière demande et renvoie une
 * réponse signée, configurable. Permet aussi de simuler une réponse forgée, hors contrat ou une panne.
 */
final class FakeBiometricsService implements BiometricsTransport
{
    /** @var array<string, mixed>|null dernière demande (JSON décodé) */
    public ?array $lastRequest = null;
    public int $calls = 0;
    /** @var array<string, true> */
    private array $seen = [];
    /** @var array<string, mixed> */
    public array $response;
    public int $status = 200;
    /** Signe la réponse avec ce secret (un autre secret simule un processus tiers qui imite le service). */
    public ?string $responseSecret = null;
    public bool $down = false;

    public function __construct(public readonly string $secret)
    {
        $this->response = self::result();
    }

    /** @return array<string, mixed> réponse conforme au contrat */
    public static function result(?int $age = 30, ?bool $expired = false, ?float $score = 0.82, bool $liveness = true, bool $mrz = true, array $reasons = []): array
    {
        return ['age' => $age, 'doc_expired' => $expired, 'face_match_score' => $score, 'liveness_passed' => $liveness, 'mrz_valid' => $mrz, 'reasons' => $reasons];
    }

    public function send(string $method, string $url, array $headers, string $body): array
    {
        if ($this->down) {
            throw new BiometricsException(BiometricsException::UNAVAILABLE, 'network_7');
        }
        $this->calls++;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ts = $headers['X-VeriAge-Timestamp'] ?? '';
        $nonce = $headers['X-VeriAge-Nonce'] ?? '';
        $expected = 'v1=' . hash_hmac('sha256', implode("\n", ['v1', $ts, $nonce, $method, $path, hash('sha256', $body)]), $this->secret);
        $authorized = ctype_digit($ts) && abs(time() - (int) $ts) <= 30 && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $nonce) === 1
            && hash_equals($expected, $headers['X-VeriAge-Signature'] ?? '') && !isset($this->seen[$nonce]);
        $this->seen[$nonce] = true;
        [$status, $payload] = $authorized ? [$this->status, $this->response] : [401, ['error' => 'unauthorized']];
        if ($authorized && $method === 'POST') {
            $this->lastRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        }
        $responseBody = json_encode($payload, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'headers' => [
            'x-veriage-signature' => 'v1=' . hash_hmac('sha256', implode("\n", ['v1', 'response', $nonce, (string) $status, hash('sha256', $responseBody)]), $this->responseSecret ?? $this->secret),
        ], 'body' => $responseBody];
    }
}
