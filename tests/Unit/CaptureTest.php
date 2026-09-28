<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Verification\Biometrics\CaptureCipher;
use App\Verification\Biometrics\CapturePayload;
use PHPUnit\Framework\TestCase;

/** Envoi des images : chiffrement AES-GCM lié à la capture, validation stricte avant tout relais. */
final class CaptureTest extends TestCase
{
    public const LIMITS = ['doc_max_bytes' => 200_000, 'doc_max_side' => 4096, 'doc_min_side' => 480,
        'frame_max_bytes' => 50_000, 'frame_max_side' => 1280, 'max_frames' => 5];

    /** JPEG valide (GD) de la taille voulue, en mémoire. */
    public static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 120, 140, 160));
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    public function testEncryptionIsBoundToSessionAndCapture(): void
    {
        $cipher = new CaptureCipher(str_repeat('k', 32));
        $body = $cipher->encrypt('{"a":1}', 'vs_A', 'cap1');
        self::assertSame('{"a":1}', $cipher->decrypt($body, 'vs_A', 'cap1'));
        self::assertNotSame($cipher->key('vs_A', 'cap1'), $cipher->key('vs_A', 'cap2'));
        foreach ([['vs_B', 'cap1'], ['vs_A', 'cap2']] as [$session, $capture]) {
            try {
                $cipher->decrypt($body, $session, $capture);
                self::fail('Déchiffré avec une autre session ou capture.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('capture_invalid', $e->getMessage());
            }
        }
        $tampered = $body;
        $tampered[20] = chr(ord($tampered[20]) ^ 1);
        $this->expectException(\InvalidArgumentException::class);
        $cipher->decrypt($tampered, 'vs_A', 'cap1');
    }

    /** @param array<string, mixed> $data */
    private static function payload(array $data = [], int $steps = 1): CapturePayload
    {
        $frame = base64_encode(self::jpeg(160, 120));

        return CapturePayload::fromJson(json_encode([
            'document_type' => 'id_card',
            'front' => base64_encode(self::jpeg(640, 480)),
            'back' => base64_encode(self::jpeg(640, 480)),
            'frames' => [['t' => 0, 'step' => 0, 'image' => $frame], ['t' => 250, 'step' => 1, 'image' => $frame]],
            ...$data,
        ], JSON_THROW_ON_ERROR), $steps, self::LIMITS);
    }

    public function testValidPayloadBecomesTheServiceRequest(): void
    {
        $payload = self::payload();
        self::assertSame(250, $payload->spanMs());
        $request = $payload->toRequest(['blink'], '2026-09-27', ['yaw_threshold' => 0.3]);
        self::assertSame(['blink'], $request['selfie']['challenge']);
        self::assertSame(['type', 'front', 'back'], array_keys($request['document']));
        self::assertSame('id_card', $request['document']['type']);
        self::assertSame(['t', 'step', 'image'], array_keys($request['selfie']['frames'][0]));
    }

    public function testInvalidPayloadsAreRefusedWithStableCodes(): void
    {
        $gif = base64_encode('GIF89a' . str_repeat("\0", 40));
        $frame = base64_encode(self::jpeg(160, 120));
        $cases = [
            'champ inconnu' => [['name' => 'x'], 'capture_invalid'],
            'type inconnu' => [['document_type' => 'driving_licence'], 'capture_invalid'],
            'verso absent' => [['back' => null], 'capture_image_invalid'],
            'GIF' => [['front' => $gif], 'capture_image_invalid'],
            'base64 invalide' => [['front' => '@@@'], 'capture_image_invalid'],
            'document trop petit' => [['front' => base64_encode(self::jpeg(300, 200))], 'capture_image_invalid'],
            'document trop lourd' => [['front' => str_repeat('A', 300_000)], 'capture_image_invalid'],
            'aucune image' => [['frames' => []], 'capture_frames_invalid'],
            'trop d\'images' => [['frames' => array_map(static fn (int $i): array => ['t' => $i, 'step' => 0, 'image' => $frame], range(1, 6))], 'capture_frames_invalid'],
            'horodatages non croissants' => [['frames' => [['t' => 100, 'step' => 0, 'image' => $frame], ['t' => 100, 'step' => 1, 'image' => $frame]]], 'capture_frames_invalid'],
            'étape inconnue' => [['frames' => [['t' => 0, 'step' => 3, 'image' => $frame]]], 'capture_frames_invalid'],
            'clé en trop dans une image' => [['frames' => [['t' => 0, 'step' => 0, 'image' => $frame, 'x' => 1]]], 'capture_frames_invalid'],
        ];
        foreach ($cases as $name => [$data, $code]) {
            try {
                self::payload($data);
                self::fail('Accepté : ' . $name);
            } catch (\InvalidArgumentException $e) {
                self::assertSame($code, $e->getMessage(), $name);
            }
        }
        $passport = self::payload(['document_type' => 'passport', 'back' => null]);
        self::assertNull($passport->back);
        $this->expectException(\InvalidArgumentException::class);
        CapturePayload::fromJson('not json', 1, self::LIMITS);
    }
}
