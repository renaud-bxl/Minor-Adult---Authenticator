<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Transport cURL vers le microservice, sur la boucle locale uniquement : http seul (aucun TLS en local),
 * aucun proxy (ni celui de l'environnement : l'image ne doit jamais sortir du serveur), aucune
 * redirection, délais bornés, réponse lue au plus sur 64 Kio.
 */
final class CurlBiometricsTransport implements BiometricsTransport
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly int $connectTimeout = 2, private readonly int $timeout = 45)
    {
    }

    public function send(string $method, string $url, array $headers, string $body): array
    {
        BiometricsClient::assertLoopbackUrl($url);
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $responseBody = '';
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'VeriAge-PHP/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responseBody): int {
                $responseBody .= $chunk;

                return strlen($responseBody) > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        if ($status === 0 || ($errno !== 0 && $errno !== CURLE_WRITE_ERROR)) {
            throw new BiometricsException(BiometricsException::UNAVAILABLE, $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network_' . $errno);
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }
}
