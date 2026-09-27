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

    public function testAuthorizationHeaderIsRecoveredBehindApacheRewrite(): void
    {
        $backup = $_SERVER;
        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer sk_test_abc'];
            self::assertSame('Bearer sk_test_abc', Request::fromGlobals()->header('Authorization'));
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer direct';
            self::assertSame('Bearer direct', Request::fromGlobals()->header('Authorization'), 'l\'en-tête direct prime');
        } finally {
            $_SERVER = $backup;
        }
    }

    public function testHtaccessDeniesDotfilesButNotWellKnown(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
        self::assertSame(1, preg_match('/^\s*RewriteRule (\S+) - \[F,L\]/m', $htaccess, $m));
        $pattern = '#' . $m[1] . '#';
        self::assertSame(1, preg_match($pattern, '.env'));
        self::assertSame(1, preg_match($pattern, 'assets/.git/config'));
        self::assertSame(0, preg_match($pattern, '.well-known/acme-challenge/abc'));
        self::assertSame(0, preg_match($pattern, '.well-known/security.txt'));
        self::assertStringContainsString('CGIPassAuth On', $htaccess);
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

    public function testRateLimitKeyGroupsIpv6By64(): void
    {
        self::assertSame('203.0.113.77', IpAddress::rateLimitKey('203.0.113.77'));
        self::assertSame('2001:db8:1:2::', IpAddress::rateLimitKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(IpAddress::rateLimitKey('2001:db8:1:2::1'), IpAddress::rateLimitKey('2001:db8:1:2::ffff'));
        self::assertNotSame(IpAddress::rateLimitKey('2001:db8:1:2::1'), IpAddress::rateLimitKey('2001:db8:1:3::1'));
    }

    public function testClientIpIgnoresForwardedForUnlessProxyIsTrusted(): void
    {
        // Aucun proxy de confiance, ou connexion directe d'un client : l'en-tête est ignoré.
        self::assertSame('198.51.100.7', IpAddress::client('198.51.100.7', '1.2.3.4', []));
        self::assertSame('198.51.100.7', IpAddress::client('198.51.100.7', '1.2.3.4', ['127.0.0.1']));

        // Proxy de confiance : entrée la plus à droite qui n'est pas un proxy de confiance.
        self::assertSame('203.0.113.9', IpAddress::client('127.0.0.1', '203.0.113.9', ['127.0.0.1']));
        self::assertSame('203.0.113.9', IpAddress::client('127.0.0.1', '6.6.6.6, 203.0.113.9', ['127.0.0.1']), 'entrée de gauche forgée par le client');
        self::assertSame('203.0.113.9', IpAddress::client('10.0.0.2', '203.0.113.9, 10.0.0.5', ['10.0.0.0/8']));
        self::assertSame('2001:db8::5', IpAddress::client('::1', '2001:db8::5', ['::1']));

        // En-tête absent ou invalide : l'adresse du proxy.
        self::assertSame('127.0.0.1', IpAddress::client('127.0.0.1', null, ['127.0.0.1']));
        self::assertSame('127.0.0.1', IpAddress::client('127.0.0.1', 'garbage', ['127.0.0.1']));

        $request = new Request('GET', '/', [], [], ['x-forwarded-for' => '203.0.113.9'], [], ['REMOTE_ADDR' => '127.0.0.1'], ['127.0.0.1']);
        self::assertSame('203.0.113.9', $request->ip());
        $direct = new Request('GET', '/', [], [], ['x-forwarded-for' => '203.0.113.9'], [], ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame('127.0.0.1', $direct->ip());
    }

    public function testCidrMatching(): void
    {
        self::assertTrue(IpAddress::matchesAny('172.16.5.4', ['172.16.0.0/12']));
        self::assertFalse(IpAddress::matchesAny('172.32.0.1', ['172.16.0.0/12']));
        self::assertTrue(IpAddress::matchesAny('192.0.2.1', ['0.0.0.0/0']));
        self::assertFalse(IpAddress::matchesAny('192.0.2.1', ['::/0', 'invalid', '192.0.2.0/33']));
        self::assertFalse(IpAddress::matchesAny('not-an-ip', ['0.0.0.0/0']));
    }

    public function testLoggerRedactsPersonalData(): void
    {
        self::assertSame('login [email] from [ip] / [ip]', Logger::redact('login Jane.Doe+x@exemple.be from 198.51.100.4 / 2001:db8::1:2'));
        self::assertSame('[ip] et [ip]', Logger::redact('fe80::1 et 2001:0db8:0000:0000:0000:0000:0000:0001'));
        // Pas de faux positif sur les appels statiques, heures ou « :: » isolés.
        self::assertSame('App\\Core\\Crypto::decodeKey à 19:29:27 ::', Logger::redact('App\\Core\\Crypto::decodeKey à 19:29:27 ::'));
        $context = Logger::exceptionContext(new \PDOException("Duplicate entry 'a@b.be' for key 'uq_users_email'", 23000));
        self::assertSame('SQLSTATE 23000, code -', $context['message']);
        $pdo = new \PDOException("Duplicate entry 'a@b.be' for key 'uq_users_email'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'a@b.be'"];
        self::assertSame('SQLSTATE 23000, code 1062', Logger::exceptionContext($pdo)['message']);
    }

    public function testJsonBodyParsing(): void
    {
        $json = static fn (string $body, string $type = 'application/json'): Request => new Request('POST', '/api/v1/x', [], [], ['content-type' => $type], [], [], [], $body);
        self::assertSame(['email' => 'a@b.be'], $json('{"email":"a@b.be"}')->json());
        self::assertSame(['a' => 1], $json('{"a":1}', 'application/merge-patch+json; charset=utf-8')->json());
        self::assertSame([], $json('{}')->json());
        $cases = [
            ['{"a":1}', 'text/plain', 415, 'unsupported_media_type'],
            ['{"a":1}', 'application/jsonp', 415, 'unsupported_media_type'],
            ['{"a":', 'application/json', 400, 'invalid_json'],
            ['[1,2]', 'application/json', 400, 'invalid_json'],
            ['"text"', 'application/json', 400, 'invalid_json'],
            [str_repeat('[', 40) . str_repeat(']', 40), 'application/json', 400, 'invalid_json'],
            ['{"a":"' . str_repeat('x', Request::MAX_JSON_BYTES) . '"}', 'application/json', 413, 'payload_too_large'],
        ];
        foreach ($cases as [$body, $type, $status, $code]) {
            try {
                $json($body, $type)->json();
                self::fail($code);
            } catch (\App\Core\ApiException $e) {
                self::assertSame([$status, $code], [$e->status(), $e->errorCode()], $code);
            }
        }
    }

    public function testHostHeaderNormalization(): void
    {
        $host = static fn (?string $value): string => (new Request('GET', '/', [], [], $value === null ? [] : ['host' => $value]))->host();
        self::assertSame('verify.veriage.eu', $host('Verify.VeriAge.eu'));
        self::assertSame('verify.veriage.eu', $host('verify.veriage.eu.'));
        self::assertSame('127.0.0.1:8000', $host('127.0.0.1:8000'));
        self::assertSame('[::1]:8000', $host('[::1]:8000'));
        foreach ([null, '', 'evil.com/path', 'a b', "x\r\ny", 'host:port', '-bad.example', str_repeat('a', 64) . '.eu'] as $bad) {
            self::assertSame('', $host($bad), (string) $bad);
        }
    }

    public function testExternalRedirectOnlyAcceptsHttpUrls(): void
    {
        self::assertSame('https://shop.example/r?a=1', \App\Core\Response::redirectAway('https://shop.example/r?a=1')->header('Location'));
        foreach (['javascript:alert(1)', '/internal', '//evil.com', "https://a.b/\r\nX: y", 'data:text/html,x'] as $bad) {
            try {
                \App\Core\Response::redirectAway($bad);
                self::fail($bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
