<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Session de vérification (table `verification_sessions`), entité immuable. Dates en UTC.
 *
 * Cycle de vie : pending → completed (résultat obtenu, majeur ou non) | failed (échec du processus :
 * codes épuisés, vérification échouée) | expired (délai dépassé). Étapes d'une session « pending » :
 * consentement → code e-mail → (réutilisation proposée) → méthode.
 */
final class VerificationSession
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';

    /** Forme d'un identifiant public (« vs_ » + 32 caractères base 62). */
    public const ID_REGEX = '/^vs_[A-Za-z0-9]{32}$/D';
    /** Motif de route (sans accolades, non supportées par le routeur) ; la longueur est contrôlée au chargement. */
    public const ROUTE_PATTERN = 'vs_[A-Za-z0-9]+';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $projectId,
        public readonly ?int $apiKeyId,
        public readonly bool $livemode,
        public readonly string $emailHash,
        public readonly string $emailEnc,
        public readonly int $minAge,
        public readonly ?string $returnUrl,
        public readonly ?string $lang,
        public readonly ?string $externalRef,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $consentAt,
        public readonly ?string $codeHash,
        public readonly ?\DateTimeImmutable $codeExpiresAt,
        public readonly int $codeAttempts,
        public readonly int $codeSends,
        public readonly ?\DateTimeImmutable $codeSentAt,
        public readonly ?\DateTimeImmutable $emailVerifiedAt,
        public readonly bool $shareOptIn,
        public readonly ?string $method,
        public readonly string $reuse,
        public readonly ?bool $resultIsAdult,
        public readonly ?\DateTimeImmutable $resultVerifiedAt,
        public readonly ?\DateTimeImmutable $resultExpiresAt,
        public readonly ?string $failureReason,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => $v === null ? null : new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC'));

        return new self(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            $row['api_key_id'] === null ? null : (int) $row['api_key_id'],
            (bool) $row['livemode'],
            (string) $row['email_hash'],
            (string) $row['email_enc'],
            (int) $row['min_age'],
            $row['return_url'] === null ? null : (string) $row['return_url'],
            $row['lang'] === null ? null : (string) $row['lang'],
            $row['external_ref'] === null ? null : (string) $row['external_ref'],
            (string) $row['status'],
            $date($row['consent_at']),
            $row['code_hash'] === null ? null : (string) $row['code_hash'],
            $date($row['code_expires_at']),
            (int) $row['code_attempts'],
            (int) $row['code_sends'],
            $date($row['code_sent_at']),
            $date($row['email_verified_at']),
            (bool) $row['share_opt_in'],
            $row['method'] === null ? null : (string) $row['method'],
            (string) $row['reuse'],
            $row['result_is_adult'] === null ? null : (bool) $row['result_is_adult'],
            $date($row['result_verified_at']),
            $date($row['result_expires_at']),
            $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
            $date($row['expires_at']) ?? throw new \UnexpectedValueException('expires_at manquant'),
            $date($row['completed_at']),
            $date($row['created_at']) ?? throw new \UnexpectedValueException('created_at manquant'),
        );
    }

    /** Session en cours (ni terminée, ni échouée, ni expirée). */
    public function isOpen(\DateTimeImmutable $now): bool
    {
        return $this->status === self::PENDING && $this->expiresAt > $now;
    }

    /** Statut effectif, l'expiration étant constatée sans attendre le cron. */
    public function effectiveStatus(\DateTimeImmutable $now): string
    {
        return $this->status === self::PENDING && $this->expiresAt <= $now ? self::EXPIRED : $this->status;
    }
}
