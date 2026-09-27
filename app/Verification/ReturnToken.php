<?php

declare(strict_types=1);

namespace App\Verification;

use App\Models\Project;
use App\Models\VerificationSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Jeton de retour « return_url?session_id=…&token=… » : JWT HS256 court (5 min), signé avec le secret
 * de signature du projet (le même que les webhooks, connu du seul client). Le client vérifie la
 * signature, « aud » (son identifiant de projet), « sub » (= session_id) et « exp ». Le résultat côté
 * navigateur reste indicatif : il doit être confirmé par le webhook ou l'API.
 */
final class ReturnToken
{
    public const ALGORITHM = 'HS256';

    public function __construct(private readonly string $issuer, private readonly int $ttl)
    {
    }

    /** @param array<string, mixed> $result résumé du résultat (voir VerificationService::sessionResult) */
    public function issue(Project $project, VerificationSession $session, array $result, string $secret, int $now): string
    {
        return JWT::encode([
            'iss' => $this->issuer,
            'aud' => $project->publicId,
            'sub' => $session->publicId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(12)),
            'livemode' => $session->livemode,
            'external_ref' => $session->externalRef,
            ...$result,
        ], $secret, self::ALGORITHM);
    }

    /**
     * Vérification (implémentation de référence pour les clients, utilisée par la démo).
     *
     * @return array<string, mixed>|null charge utile si le jeton est valide pour ce projet et cette session
     */
    public static function verify(string $token, string $secret, string $projectPublicId, string $sessionId): ?array
    {
        try {
            $claims = (array) JWT::decode($token, new Key($secret, self::ALGORITHM));
        } catch (\Throwable) {
            return null;
        }

        return ($claims['aud'] ?? null) === $projectPublicId && ($claims['sub'] ?? null) === $sessionId ? $claims : null;
    }
}
