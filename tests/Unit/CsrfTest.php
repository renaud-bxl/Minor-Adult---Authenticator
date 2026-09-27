<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySessionHandler;

final class CsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new ArraySessionHandler(), 's', true, 3600);
        $this->session->start(null);
    }

    public function testTokenIsStableWithinSession(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $csrf->token());
        self::assertSame($token, (new Csrf($this->session))->token());
    }

    public function testValidation(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();
        self::assertTrue($csrf->validate($token));
        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(''));
        self::assertFalse($csrf->validate(strrev($token)));
        self::assertFalse($csrf->validate($token . 'x'));
    }

    public function testNoTokenInSessionNeverValidates(): void
    {
        self::assertFalse((new Csrf($this->session))->validate(''));
        self::assertFalse((new Csrf($this->session))->validate(str_repeat('0', 64)));
    }

    public function testRotateInvalidatesPreviousToken(): void
    {
        $csrf = new Csrf($this->session);
        $old = $csrf->token();
        $new = $csrf->rotate();
        self::assertNotSame($old, $new);
        self::assertFalse($csrf->validate($old));
        self::assertTrue($csrf->validate($new));
    }

    public function testTokensDifferBetweenSessions(): void
    {
        $other = new Session(new ArraySessionHandler(), 's', true, 3600);
        $other->start(null);
        $token = (new Csrf($this->session))->token();
        self::assertFalse((new Csrf($other))->validate($token));
    }
}
