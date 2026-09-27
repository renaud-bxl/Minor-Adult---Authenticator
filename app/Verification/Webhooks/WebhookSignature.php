<?php

declare(strict_types=1);

namespace App\Verification\Webhooks;

/**
 * Signature des webhooks : en-tête « X-VeriAge-Signature: t={horodatage},v1={hex} » où
 * v1 = HMAC-SHA256(secret, "{t}.{corps brut}").
 *
 * Côté client (vérification), trois contrôles : signature valide en temps constant, horodatage dans
 * la fenêtre de tolérance (5 min par défaut, dans les deux sens : anti-rejeu), puis déduplication par
 * identifiant d'événement (« id », aussi dans X-VeriAge-Event-Id) car une livraison peut être rejouée
 * légitimement par nos relances. Cette classe sert aussi de référence d'implémentation aux clients.
 */
final class WebhookSignature
{
    public const HEADER = 'X-VeriAge-Signature';
    public const DEFAULT_TOLERANCE = 300;

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return self::signAll($payload, [$secret], $timestamp);
    }

    /**
     * Une signature v1 par secret (rotation avec recouvrement : nouveau secret puis ancien). Le client
     * accepte l'en-tête si l'UNE des signatures correspond à son secret.
     *
     * @param non-empty-list<string> $secrets
     */
    public static function signAll(string $payload, array $secrets, int $timestamp): string
    {
        $header = 't=' . $timestamp;
        foreach ($secrets as $secret) {
            $header .= ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        }

        return $header;
    }

    /**
     * @return bool true si l'en-tête contient une signature v1 valide et un horodatage dans la fenêtre
     */
    public static function verify(string $payload, string $header, string $secret, int $now, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        if ($secret === '' || strlen($header) > 1024) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && preg_match('/^\d{1,12}$/D', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            // Pas de sortie anticipée : durée indépendante de la position de la bonne signature.
            $valid = hash_equals($expected, $signature) || $valid;
        }

        return $valid;
    }
}
