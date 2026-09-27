<?php

declare(strict_types=1);

namespace App\Verification\Webhooks;

/**
 * Transport cURL durci contre la SSRF :
 * - connexion forcée sur l'adresse IP vérifiée (CURLOPT_RESOLVE) : aucune seconde résolution DNS ;
 * - aucune redirection suivie, aucun proxy (ni celui de l'environnement), protocole https seul
 *   (http toléré uniquement en développement) ;
 * - délais courts, réponse lue au plus sur 64 Kio puis ignorée (jamais stockée).
 */
final class CurlWebhookTransport implements WebhookTransport
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly bool $allowHttp = false)
    {
    }

    public function post(string $url, string $ip, array $headers, string $body, int $connectTimeout, int $timeout): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = 0;
        $handle = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [...$lines, 'Expect:'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => $this->allowHttp ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'VeriAge-Webhooks/1.0',
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received): int {
                $received += strlen($chunk);

                return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);

        if ($status > 0) {
            return ['status' => $status, 'error' => null];
        }

        return ['status' => null, 'error' => match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => 'timeout',
            $errno === CURLE_COULDNT_CONNECT => 'connection_refused',
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION], true) => 'tls_error',
            $ok === false => 'network_error_' . $errno,
            default => 'no_response',
        }];
    }
}
