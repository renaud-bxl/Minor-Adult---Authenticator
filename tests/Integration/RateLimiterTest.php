<?php

declare(strict_types=1);

namespace Tests\Integration;

final class RateLimiterTest extends IntegrationTestCase
{
    public function testAllowsUpToLimitThenBlocksWithRetryAfter(): void
    {
        $limiter = $this->app->rateLimiter();
        for ($i = 1; $i <= 3; $i++) {
            $result = $limiter->attempt('test', '198.51.100.1', 3, 60);
            self::assertTrue($result->allowed);
            self::assertSame(3 - $i, $result->remaining);
        }
        $blocked = $limiter->attempt('test', '198.51.100.1', 3, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertGreaterThan(0, $blocked->retryAfter);
        self::assertLessThanOrEqual(60, $blocked->retryAfter);
    }

    public function testKeysAreIndependentAndClearable(): void
    {
        $limiter = $this->app->rateLimiter();
        $limiter->attempt('test', 'a', 1, 60);
        self::assertFalse($limiter->attempt('test', 'a', 1, 60)->allowed);
        self::assertTrue($limiter->attempt('test', 'b', 1, 60)->allowed, 'autre identifiant');
        self::assertTrue($limiter->attempt('other', 'a', 1, 60)->allowed, 'autre compteur');

        $limiter->clear('test', 'a');
        self::assertTrue($limiter->attempt('test', 'a', 1, 60)->allowed);
    }

    public function testSlidingWindowReleasesAfterExpiry(): void
    {
        $limiter = $this->app->rateLimiter();
        self::assertTrue($limiter->attempt('slide', 'x', 1, 1)->allowed);
        self::assertFalse($limiter->attempt('slide', 'x', 1, 1)->allowed);
        usleep(1_100_000);
        self::assertTrue($limiter->attempt('slide', 'x', 1, 1)->allowed);
    }

    public function testRedisKeysContainNoPersonalData(): void
    {
        $this->app->rateLimiter()->attempt('login_email', 'jane.doe@example.be', 5, 60);
        $keys = $this->app->redis()->keys('*');
        self::assertCount(1, $keys);
        self::assertStringNotContainsString('jane', $keys[0]);
        self::assertMatchesRegularExpression('/rl:login_email:[a-f0-9]{64}$/', $keys[0]);
        self::assertGreaterThan(0, $this->app->redis()->pttl('rl:login_email:' . substr($keys[0], -64)));
    }
}
