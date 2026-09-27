<?php

declare(strict_types=1);

namespace App\Services;

final class LoginResult
{
    public const SUCCESS = 'success';
    public const INVALID = 'invalid';
    public const UNVERIFIED = 'unverified';

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $account
     */
    private function __construct(public readonly string $status, public readonly ?array $user = null, public readonly ?array $account = null)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $account
     */
    public static function success(array $user, array $account): self
    {
        return new self(self::SUCCESS, $user, $account);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    /** @param array<string, mixed> $user */
    public static function unverified(array $user): self
    {
        return new self(self::UNVERIFIED, $user);
    }
}
