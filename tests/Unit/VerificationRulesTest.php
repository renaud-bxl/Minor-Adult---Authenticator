<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Project;
use App\Verification\MethodRegistry;
use App\Verification\Methods\MockProvider;
use App\Verification\VerificationOutcome;
use App\Verification\VerificationService;
use PHPUnit\Framework\TestCase;

/** Règles métier pures : couverture d'âge, méthodes, résultats, normalisation d'adresse. */
final class VerificationRulesTest extends TestCase
{
    public function testAgeCoverage(): void
    {
        $adult18 = ['is_adult' => 1, 'min_age' => 18];
        $minor18 = ['is_adult' => 0, 'min_age' => 18];
        self::assertTrue(VerificationService::covers($adult18, 16));
        self::assertTrue(VerificationService::covers($adult18, 18));
        self::assertFalse(VerificationService::covers($adult18, 21), 'majeur à 18 ≠ majeur à 21');
        self::assertTrue(VerificationService::covers($minor18, 21), 'pas 18 ans ⇒ pas 21 ans');
        self::assertFalse(VerificationService::covers($minor18, 16), 'pas 18 ans ne dit rien de 16 ans');
    }

    public function testMockProviderIsSandboxOnly(): void
    {
        $mock = new MockProvider();
        self::assertTrue($mock->isAvailable(false));
        self::assertFalse($mock->isAvailable(true));
        self::assertFalse($mock->requiresTopLevelWindow());
        $registry = new MethodRegistry($mock);
        $project = new Project(1, 'prj', 1, 'P', 18, 365, [], ['id_document_face'], false, '', '', '');
        self::assertSame([$mock], $registry->availableFor($project, false));
        self::assertSame([], $registry->availableFor($project, true));
        self::assertNull($registry->find($project, false, 'eid_be'), 'non implémentée');
    }

    public function testOutcomes(): void
    {
        $ok = VerificationOutcome::verified(false);
        self::assertTrue($ok->verified);
        self::assertFalse($ok->isAdult);
        self::assertSame('mock_failure', VerificationOutcome::failed('mock_failure')->failureReason);
        $this->expectException(\InvalidArgumentException::class);
        VerificationOutcome::failed('Free text with spaces');
    }

    public function testEmailNormalization(): void
    {
        self::assertSame('user@example.be', VerificationService::normalizeEmail('  User@Example.BE '));
        self::assertSame('josé@exemple.be', VerificationService::normalizeEmail("Jose\u{301}@exemple.be"));
        foreach (['', 'nope', "a@b.be\r\nBcc: x@y.z", "a\u{202E}@b.be", str_repeat('a', 250) . '@b.be', "bad\xFF@b.be"] as $bad) {
            self::assertNull(VerificationService::normalizeEmail($bad), bin2hex($bad));
        }
    }
}
