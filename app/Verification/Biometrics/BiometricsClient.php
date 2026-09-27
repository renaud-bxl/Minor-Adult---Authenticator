<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Client du microservice biométrique local (biometrics/, FastAPI sur 127.0.0.1).
 *
 * Authentification mutuelle par secret partagé (HMAC-SHA256), protocole décrit dans
 * biometrics/veriage_biometrics/security.py :
 * - requête : X-VeriAge-Timestamp, X-VeriAge-Nonce (unique), X-VeriAge-Signature
 *   = v1=HMAC("v1\n{ts}\n{nonce}\n{MÉTHODE}\n{chemin}\n{sha256(corps)}") ; le service refuse un horodatage
 *   hors d'une fenêtre de 30 s et tout nonce déjà vu (anti-rejeu) ;
 * - réponse : X-VeriAge-Signature = v1=HMAC("v1\nresponse\n{nonce}\n{statut}\n{sha256(corps)}"), liée au
 *   nonce de la requête. Une réponse non signée, mal signée ou hors contrat est refusée : un processus
 *   tiers qui occuperait le port (serveur partagé) ne peut pas forger un « visage conforme ».
 */
final class BiometricsClient
{
    public function __construct(
        private readonly BiometricsTransport $transport,
        private readonly string $baseUrl,
        private readonly string $secret,
        /** @var \Closure(): int */
        private readonly ?\Closure $clock = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('BIOMETRICS_SECRET doit contenir au moins 32 caractères.');
        }
        self::assertLoopbackUrl($baseUrl);
    }

    /** L'URL du service doit viser la boucle locale en http (le service n'est jamais exposé). */
    public static function assertLoopbackUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (($parts['scheme'] ?? '') !== 'http' || isset($parts['user']) || isset($parts['pass'])
            || !in_array($host, ['127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException('BIOMETRICS_URL doit viser http://127.0.0.1:port ou http://[::1]:port.');
        }
    }

    /**
     * Analyse document + selfie.
     *
     * @param array<string, mixed> $request corps attendu par POST /v1/analyze
     *
     * @throws BiometricsException
     */
    public function analyze(array $request): AnalysisResult
    {
        [$status, $data] = $this->call('POST', '/v1/analyze', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return match (true) {
            $status === 200 => AnalysisResult::fromArray($data),
            $status === 503 => throw new BiometricsException(BiometricsException::BUSY),
            $status === 422 => throw new BiometricsException(BiometricsException::REJECTED, self::errorCode($data)),
            default => throw new BiometricsException(BiometricsException::UNAVAILABLE, 'http_' . $status),
        };
    }

    /**
     * État du service (modèles chargés, Tesseract, MediaPipe ou repli).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        [$status, $data] = $this->call('GET', '/v1/health', '');
        if ($status !== 200) {
            throw new BiometricsException(BiometricsException::UNAVAILABLE, 'http_' . $status);
        }

        return $data;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, string $body): array
    {
        $timestamp = (string) ($this->clock !== null ? ($this->clock)() : time());
        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $response = $this->transport->send($method, rtrim($this->baseUrl, '/') . $path, [
            'Content-Type' => 'application/json',
            'X-VeriAge-Timestamp' => $timestamp,
            'X-VeriAge-Nonce' => $nonce,
            'X-VeriAge-Signature' => $this->sign(implode("\n", ['v1', $timestamp, $nonce, $method, $path, hash('sha256', $body)])),
        ], $body);

        $expected = $this->sign(implode("\n", ['v1', 'response', $nonce, (string) $response['status'], hash('sha256', $response['body'])]));
        if (!hash_equals($expected, (string) ($response['headers']['x-veriage-signature'] ?? ''))) {
            throw new BiometricsException(BiometricsException::PROTOCOL, 'signature');
        }
        try {
            $data = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BiometricsException(BiometricsException::PROTOCOL, 'json');
        }
        if (!is_array($data)) {
            throw new BiometricsException(BiometricsException::PROTOCOL, 'json');
        }

        return [$response['status'], $data];
    }

    private function sign(string $base): string
    {
        return 'v1=' . hash_hmac('sha256', $base, $this->secret);
    }

    /** @param array<string, mixed> $data */
    private static function errorCode(array $data): string
    {
        $code = $data['error'] ?? '';

        return is_string($code) && preg_match('/^[a-z_]{1,40}$/D', $code) === 1 ? $code : 'unknown';
    }
}
