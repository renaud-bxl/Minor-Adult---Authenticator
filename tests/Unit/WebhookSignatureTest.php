<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

/** Signature HMAC-SHA256 des webhooks et fenêtre anti-rejeu de 5 minutes. */
final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdefghijABCDEFGHIJ012345';
    private const BODY = '{"id":"evt_1","type":"verification.completed","data":{"is_adult":true}}';

    public function testHeaderFormatAndKnownVector(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, 1790000000);
        self::assertSame('t=1790000000,v1=' . hash_hmac('sha256', '1790000000.' . self::BODY, self::SECRET), $header);
        self::assertMatchesRegularExpression('/^t=\d+,v1=[a-f0-9]{64}$/', $header);
    }

    public function testValidSignatureInsideTheWindow(): void
    {
        $now = 1790000000;
        foreach ([0, 299, -299, 300, -300] as $skew) {
            self::assertTrue(WebhookSignature::verify(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, $now + $skew), self::SECRET, $now), (string) $skew);
        }
    }

    public function testReplayOutsideTheWindowIsRejected(): void
    {
        $now = 1790000000;
        self::assertFalse(WebhookSignature::verify(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, $now - 301), self::SECRET, $now), 'ancien');
        self::assertFalse(WebhookSignature::verify(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, $now + 301), self::SECRET, $now), 'futur');
        self::assertFalse(WebhookSignature::verify(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, $now - 60), self::SECRET, $now, 30), 'tolérance réglable');
    }

    public function testTamperingIsDetected(): void
    {
        $now = 1790000000;
        $header = WebhookSignature::sign(self::BODY, self::SECRET, $now);
        self::assertFalse(WebhookSignature::verify(str_replace('true', 'false', self::BODY), $header, self::SECRET, $now), 'corps modifié');
        self::assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET . 'x', $now), 'autre secret');
        self::assertFalse(WebhookSignature::verify(self::BODY, $header, '', $now), 'secret vide');
        // Horodatage modifié (rejeu « rafraîchi ») : la signature ne correspond plus.
        self::assertFalse(WebhookSignature::verify(self::BODY, preg_replace('/^t=\d+/', 't=' . ($now + 10), $header), self::SECRET, $now + 10));
    }

    public function testMalformedHeadersAreRejected(): void
    {
        $now = 1790000000;
        $v1 = hash_hmac('sha256', $now . '.' . self::BODY, self::SECRET);
        foreach (['', 't=' . $now, 'v1=' . $v1, 't=abc,v1=' . $v1, 't=' . $now . ',v1=' . strtoupper($v1), 't=' . $now . ',v0=' . $v1, str_repeat('t=1,', 300)] as $header) {
            self::assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET, $now), $header);
        }
    }

    public function testAnyOfSeveralSignaturesMayMatch(): void
    {
        $now = 1790000000;
        $good = hash_hmac('sha256', $now . '.' . self::BODY, self::SECRET);
        self::assertTrue(WebhookSignature::verify(self::BODY, 't=' . $now . ',v1=' . str_repeat('0', 64) . ',v1=' . $good, self::SECRET, $now));
    }
}
