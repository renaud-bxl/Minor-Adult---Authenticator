<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\UrlGuard;
use PHPUnit\Framework\TestCase;

/** Protection SSRF des URL clientes (return_url, webhooks) et domaines autorisés. */
final class UrlGuardTest extends TestCase
{
    private const ORIGINS = ['https://shop.example', 'https://*.brand.example', 'https://api.shop.example:8443'];

    public function testAcceptedUrls(): void
    {
        $guard = new UrlGuard();
        foreach (['https://shop.example/', 'https://shop.example:443/x?y=1', 'https://a.brand.example/r', 'https://x.y.brand.example/r', 'https://api.shop.example:8443/h'] as $url) {
            self::assertNull($guard->checkUrl($url, self::ORIGINS), $url);
        }
    }

    public function testRejectedUrls(): void
    {
        $guard = new UrlGuard();
        $cases = [
            'http://shop.example/' => 'insecure_scheme',
            'ftp://shop.example/' => 'insecure_scheme',
            'gopher://shop.example/' => 'insecure_scheme',
            'https://brand.example/' => 'origin_not_allowed',
            'https://shop.example.attacker.net/' => 'origin_not_allowed',
            'https://api.shop.example/h' => 'origin_not_allowed',
            'https://a_b.brand.example/' => 'origin_not_allowed',
            'https://u:p@shop.example/' => 'credentials_not_allowed',
            'https://shop.example/#x' => 'fragment_not_allowed',
            'https://127.0.0.1/' => 'private_address',
            'https://2130706433/' => 'private_address',
            'https://localhost/' => 'private_address',
            'https://intranet/' => 'private_address',
            'https://[::ffff:10.0.0.1]/' => 'private_address',
            'https://169.254.169.254/latest' => 'private_address',
            "https://shop.example/\r\nX: y" => 'invalid_url',
            'https:///nohost' => 'invalid_url',
            '' => 'invalid_url',
        ];
        foreach ($cases as $url => $code) {
            self::assertSame($code, $guard->checkUrl($url, self::ORIGINS), $url);
        }
    }

    public function testPrivateAndReservedAddresses(): void
    {
        foreach (['10.1.2.3', '172.16.0.1', '192.168.1.1', '127.0.0.1', '0.0.0.0', '100.64.0.1', '169.254.1.1', '192.0.2.1', '198.51.100.1',
            '203.0.113.1', '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '::', 'fc00::1', 'fd12::1', 'fe80::1', 'ff02::1',
            '::ffff:192.168.0.1', '64:ff9b::a00:1', '2001:db8::1', '2002:a00:1::1', 'not-an-ip'] as $ip) {
            self::assertFalse(UrlGuard::isPublicIp($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4001::1'] as $ip) {
            self::assertTrue(UrlGuard::isPublicIp($ip), $ip);
        }
    }

    public function testResolutionRequiresAllAddressesToBePublic(): void
    {
        $dns = ['ok.example' => ['93.184.216.34'], 'mixed.example' => ['93.184.216.34', '10.0.0.5'], 'internal.example' => ['127.0.0.1']];
        $guard = new UrlGuard(false, static fn (string $h): array => $dns[$h] ?? []);
        self::assertSame(['93.184.216.34', null], $guard->resolvePublic('ok.example'));
        self::assertSame([null, 'private_address'], $guard->resolvePublic('mixed.example'));
        self::assertSame([null, 'private_address'], $guard->resolvePublic('internal.example'));
        self::assertSame([null, 'dns_failed'], $guard->resolvePublic('nxdomain.example'));
        self::assertSame([null, 'private_address'], $guard->resolvePublic('[::1]'));
    }

    public function testOriginNormalization(): void
    {
        $guard = new UrlGuard();
        self::assertSame('https://shop.example', $guard->normalizeOrigin(' HTTPS://Shop.Example:443 '));
        self::assertSame('https://shop.example:8443', $guard->normalizeOrigin('https://shop.example:8443'));
        self::assertSame('https://*.brand.example', $guard->normalizeOrigin('https://*.brand.example'));
        foreach (['http://shop.example', 'https://*.example', 'https://*.10.0.0.1', 'https://shop.example/', 'https://sh op.example', 'https://shop.example:0',
            'https://shop.example:70000', 'https://127.0.0.1', 'https://*', 'https://-bad.example', 'shop.example'] as $bad) {
            self::assertNull($guard->normalizeOrigin($bad), $bad);
        }
    }

    public function testDevelopmentModeAllowsLocalHttpOnly(): void
    {
        $dev = new UrlGuard(true);
        self::assertNull($dev->checkUrl('http://127.0.0.1:8001/demo/webhook', ['http://127.0.0.1:8001']));
        self::assertSame('http://127.0.0.1:8001', $dev->normalizeOrigin('http://127.0.0.1:8001'));
        self::assertSame('origin_not_allowed', $dev->checkUrl('http://127.0.0.1:9999/x', ['http://127.0.0.1:8001']), 'toujours limité aux domaines du projet');
        self::assertSame('invalid_url', $dev->checkUrl('file:///etc/passwd', ['http://127.0.0.1:8001']));
        self::assertSame('insecure_scheme', $dev->checkUrl('ftp://127.0.0.1:8001/x', ['http://127.0.0.1:8001']));
        self::assertSame('insecure_scheme', $dev->checkUrl('http://shop.example/x', ['http://shop.example']), 'http vers un hôte public : jamais');
        self::assertNull($dev->normalizeOrigin('http://shop.example'));
        self::assertSame('http://demo.localhost:8001', $dev->normalizeOrigin('http://demo.localhost:8001'));
    }
}
