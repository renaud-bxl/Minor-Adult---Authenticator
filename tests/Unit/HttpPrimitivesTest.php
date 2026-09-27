<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\IpAddress;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use PHPUnit\Framework\TestCase;

/** Primitives HTTP et utilitaires : redirections, en-têtes, échappement, anonymisation. */
final class HttpPrimitivesTest extends TestCase
{
    public function testRedirectOnlyAcceptsInternalPaths(): void
    {
        self::assertSame('/fr/login', Response::redirect('/fr/login')->header('location'));
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)'] as $target) {
            try {
                Response::redirect($target);
                self::fail('Redirection externe acceptée : ' . $target);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Response())->setHeader('X-Test', "a\r\nSet-Cookie: x=y");
    }

    public function testRequestInputIgnoresArrays(): void
    {
        $request = new Request('POST', '/', ['q' => ['a']], ['email' => ['x@y.be'], 'name' => 'ok']);
        self::assertSame('', $request->input('email'));
        self::assertSame('ok', $request->input('name'));
        self::assertSame('', $request->query('q'));
    }

    public function testPathNormalization(): void
    {
        self::assertSame('/', Request::normalizePath(''));
        self::assertSame('/fr', Request::normalizePath('//fr///'));
    }

    public function testEscapingHelper(): void
    {
        self::assertSame('&lt;script&gt;&quot;x&quot;&apos;', e('<script>"x"\''));
        self::assertSame('', e(null));
    }

    public function testViewRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new View(dirname(__DIR__, 2) . '/app/Views'))->render('../../etc/passwd');
    }

    public function testIpTruncation(): void
    {
        self::assertSame('203.0.113.0', IpAddress::truncate('203.0.113.77'));
        self::assertSame('2001:db8:abcd::', IpAddress::truncate('2001:db8:abcd:12:34::1'));
        self::assertNull(IpAddress::truncate('not-an-ip'));
    }

    public function testLoggerRedactsPersonalData(): void
    {
        self::assertSame('login [email] from [ip] / [ip]', Logger::redact('login Jane.Doe+x@exemple.be from 198.51.100.4 / 2001:db8::1:2'));
        self::assertSame('[ip] et [ip]', Logger::redact('fe80::1 et 2001:0db8:0000:0000:0000:0000:0000:0001'));
        // Pas de faux positif sur les appels statiques, heures ou « :: » isolés.
        self::assertSame('App\\Core\\Crypto::decodeKey à 19:29:27 ::', Logger::redact('App\\Core\\Crypto::decodeKey à 19:29:27 ::'));
        $context = Logger::exceptionContext(new \PDOException("Duplicate entry 'a@b.be' for key 'uq_users_email'", 23000));
        self::assertSame('SQLSTATE 23000', $context['message']);
    }
}
