<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordHasher;
use App\Services\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy(12, 1024);
    }

    public function testAcceptsLongEnoughPassword(): void
    {
        self::assertNull($this->policy->validate('correct horse battery', 'a@b.be'));
        self::assertNull($this->policy->validate(str_repeat('x', 12)));
        self::assertNull($this->policy->validate(str_repeat('x', 1024)));
    }

    public function testRejectsTooShortCountingCharactersNotBytes(): void
    {
        self::assertSame(['site.validation.password_too_short', ['min' => 12]], $this->policy->validate('short'));
        // 11 caractères multi-octets (22 octets) : toujours trop court.
        self::assertSame('site.validation.password_too_short', $this->policy->validate(str_repeat('é', 11))[0] ?? null);
        self::assertNull($this->policy->validate(str_repeat('é', 12)));
    }

    public function testRejectsTooLong(): void
    {
        self::assertSame(['site.validation.password_too_long', ['max' => 1024]], $this->policy->validate(str_repeat('x', 1025)));
    }

    public function testRejectsEmailAsPassword(): void
    {
        self::assertSame(
            ['site.validation.password_same_as_email', []],
            $this->policy->validate('Someone@Example.be', 'someone@example.be'),
        );
    }

    public function testHasherUsesArgon2id(): void
    {
        $hasher = new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $hash = $hasher->hash('correct horse battery');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery', $hash));
        self::assertFalse($hasher->verify('wrong horse battery', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new PasswordHasher(['memory_cost' => 16384, 'time_cost' => 1, 'threads' => 1]))->needsRehash($hash));
    }
}
