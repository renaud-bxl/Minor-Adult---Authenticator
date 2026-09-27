<?php

declare(strict_types=1);

namespace App\Verification\Methods;

use App\Models\VerificationSession;
use App\Verification\VerificationMethodInterface;
use App\Verification\VerificationOutcome;

/**
 * Méthode simulée, réservée à la sandbox (clés « sk_test_ ») : l'utilisateur de test choisit le
 * résultat (majeur, mineur, échec). Jamais proposée pour une session de production.
 */
final class MockProvider implements VerificationMethodInterface
{
    public const ID = 'mock';
    public const OUTCOMES = ['adult', 'minor', 'fail'];

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(bool $livemode): bool
    {
        return !$livemode;
    }

    public function requiresTopLevelWindow(): bool
    {
        return false;
    }

    public function verify(VerificationSession $session, array $input): VerificationOutcome
    {
        if ($session->livemode) {
            throw new \LogicException('MockProvider est réservé à la sandbox.');
        }

        return match ($input['outcome'] ?? '') {
            'adult' => VerificationOutcome::verified(true),
            'minor' => VerificationOutcome::verified(false),
            'fail' => VerificationOutcome::failed('mock_failure'),
            default => throw new \InvalidArgumentException('Résultat simulé inconnu.'),
        };
    }
}
