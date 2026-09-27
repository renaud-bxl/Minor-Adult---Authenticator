<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new Crypto(str_repeat('k', 32), str_repeat('h', 32));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'utilisateur.éà@exemple.be';
        $payload = $this->crypto->encrypt($plaintext, 'client:42');
        self::assertNotSame($plaintext, $payload);
        self::assertStringNotContainsString('exemple', $payload);
        self::assertSame($plaintext, $this->crypto->decrypt($payload, 'client:42'));
        self::assertSame('', $this->crypto->decrypt($this->crypto->encrypt('')));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        self::assertNotSame($this->crypto->encrypt('a@b.be'), $this->crypto->encrypt('a@b.be'));
        self::assertSame(1 + 12 + 16 + 6, strlen($this->crypto->encrypt('a@b.be')));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $payload = $this->crypto->encrypt('a@b.be');
        $payload[strlen($payload) - 1] = chr(ord($payload[strlen($payload) - 1]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $this->crypto->decrypt($payload);
    }

    public function testWrongAssociatedDataIsRejected(): void
    {
        $payload = $this->crypto->encrypt('a@b.be', 'client:1');
        $this->expectException(\RuntimeException::class);
        $this->crypto->decrypt($payload, 'client:2');
    }

    public function testWrongKeyIsRejected(): void
    {
        $payload = $this->crypto->encrypt('a@b.be');
        $this->expectException(\RuntimeException::class);
        (new Crypto(str_repeat('x', 32), str_repeat('h', 32)))->decrypt($payload);
    }

    public function testTruncatedOrUnversionedPayloadIsRejected(): void
    {
        foreach (['', "\x01short", "\x02" . str_repeat('a', 40)] as $payload) {
            try {
                $this->crypto->decrypt($payload);
                self::fail('Chiffré invalide accepté');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmailHashIsStablePerSaltAndNormalized(): void
    {
        $hash = $this->crypto->hashEmail('User@Example.be', 'salt-client-1');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame($hash, $this->crypto->hashEmail('  user@example.BE ', 'salt-client-1'));
        self::assertNotSame($hash, $this->crypto->hashEmail('user@example.be', 'salt-client-2'));
        self::assertNotSame($hash, $this->crypto->hashEmail('other@example.be', 'salt-client-1'));
        // Poivre serveur : une autre clé de hash donne une autre empreinte pour le même sel.
        self::assertNotSame($hash, (new Crypto(str_repeat('k', 32), str_repeat('z', 32)))->hashEmail('user@example.be', 'salt-client-1'));
    }

    public function testUnicodeNormalization(): void
    {
        // « é » précomposé (NFC) et décomposé (NFD) désignent la même adresse.
        self::assertSame(
            $this->crypto->hashEmail("jos\u{00E9}@exemple.be", 's'),
            $this->crypto->hashEmail("jose\u{0301}@exemple.be", 's'),
        );
    }

    public function testKeyringDecryptsOldVersionsAndEncryptsWithTheCurrentOne(): void
    {
        $v1 = new Crypto(str_repeat('k', 32), str_repeat('h', 32));
        $old = $v1->encrypt('a@b.be', 'ctx');
        self::assertSame("\x01", $old[0]);

        $v2 = new Crypto(str_repeat('n', 32), str_repeat('h', 32), 2, [1 => str_repeat('k', 32)]);
        self::assertSame('a@b.be', $v2->decrypt($old, 'ctx'), 'ancienne clé : lecture seule');
        self::assertTrue($v2->needsReencryption($old));
        $fresh = $v2->encrypt('a@b.be', 'ctx');
        self::assertSame("\x02", $fresh[0]);
        self::assertFalse($v2->needsReencryption($fresh));

        // Sans l'ancienne clé dans le trousseau, l'ancien chiffré est illisible.
        $this->expectException(\RuntimeException::class);
        (new Crypto(str_repeat('n', 32), str_repeat('h', 32), 2))->decrypt($old, 'ctx');
    }

    public function testKeyringConfiguration(): void
    {
        $raw = random_bytes(32);
        self::assertSame([3 => $raw], Crypto::parseKeyring(' 3:base64:' . base64_encode($raw) . ' , '));
        self::assertSame([], Crypto::parseKeyring(''));
        foreach (['x:base64:' . base64_encode($raw), '1:short', '1:base64:' . base64_encode($raw) . ',1:base64:' . base64_encode($raw)] as $invalid) {
            try {
                Crypto::parseKeyring($invalid);
                self::fail('Trousseau invalide accepté : ' . $invalid);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[0, []], [256, []], [2, [2 => $raw]], [2, [1 => 'short']]] as [$version, $previous]) {
            try {
                new Crypto(str_repeat('n', 32), str_repeat('h', 32), $version, $previous);
                self::fail('Version ou ancienne clé invalide acceptée');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKeysMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto('short', str_repeat('h', 32));
    }

    public function testDecodeKey(): void
    {
        $raw = random_bytes(32);
        self::assertSame($raw, Crypto::decodeKey('base64:' . base64_encode($raw)));
        self::assertSame($raw, Crypto::decodeKey(base64_encode($raw)));
        $this->expectException(\InvalidArgumentException::class);
        Crypto::decodeKey('base64:' . base64_encode('trop court'));
    }

    public function testRandomTokensAndHashes(): void
    {
        $token = Crypto::randomToken();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertNotSame($token, Crypto::randomToken());
        self::assertSame(32, strlen(Crypto::hashToken($token)));
        self::assertSame(Crypto::hashToken($token), Crypto::hashToken($token));
    }
}
