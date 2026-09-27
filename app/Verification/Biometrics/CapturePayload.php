<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Contenu d'une capture (après déchiffrement), validé AVANT tout relais : type de document, recto,
 * verso (carte d'identité), séquence du selfie. Images en base64 strict, JPEG ou PNG seulement
 * (signature et en-tête lus en mémoire par getimagesizefromstring, sans décodage), tailles et
 * dimensions bornées. Rien n'est écrit sur disque ; l'objet ne vit que le temps de la requête.
 *
 * Les défis ne viennent JAMAIS du navigateur : ils sont ajoutés par le serveur (ChallengeStore).
 */
final class CapturePayload
{
    public const DOCUMENT_TYPES = ['id_card', 'passport'];

    /**
     * @param list<array{t: int, step: int, image: string}> $frames
     */
    private function __construct(
        public readonly string $documentType,
        public readonly string $front,
        public readonly ?string $back,
        public readonly array $frames,
    ) {
    }

    /**
     * @param array{doc_max_bytes: int, doc_max_side: int, doc_min_side: int, frame_max_bytes: int, frame_max_side: int, max_frames: int} $limits
     *
     * @throws \InvalidArgumentException code stable (jamais la donnée reçue)
     */
    public static function fromJson(string $json, int $steps, array $limits): self
    {
        try {
            $data = json_decode($json, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('capture_invalid');
        }
        if (!is_array($data) || array_diff(array_keys($data), ['document_type', 'front', 'back', 'frames']) !== []
            || !in_array($data['document_type'] ?? null, self::DOCUMENT_TYPES, true)) {
            throw new \InvalidArgumentException('capture_invalid');
        }
        $front = self::image($data['front'] ?? null, $limits['doc_max_bytes'], $limits['doc_max_side'], $limits['doc_min_side']);
        $back = null;
        if ($data['document_type'] === 'id_card') {
            $back = self::image($data['back'] ?? null, $limits['doc_max_bytes'], $limits['doc_max_side'], $limits['doc_min_side']);
        } elseif (($data['back'] ?? null) !== null) {
            throw new \InvalidArgumentException('capture_invalid');
        }

        $frames = $data['frames'] ?? null;
        if (!is_array($frames) || !array_is_list($frames) || $frames === [] || count($frames) > $limits['max_frames']) {
            throw new \InvalidArgumentException('capture_frames_invalid');
        }
        $clean = [];
        $previous = -1;
        foreach ($frames as $frame) {
            if (!is_array($frame) || array_keys($frame) !== ['t', 'step', 'image'] || !is_int($frame['t']) || !is_int($frame['step'])
                || $frame['t'] <= $previous || $frame['t'] > 600_000 || $frame['step'] < 0 || $frame['step'] > $steps) {
                throw new \InvalidArgumentException('capture_frames_invalid');
            }
            $previous = $frame['t'];
            $clean[] = ['t' => $frame['t'], 'step' => $frame['step'],
                'image' => self::image($frame['image'], $limits['frame_max_bytes'], $limits['frame_max_side'], 64)];
        }

        return new self($data['document_type'], $front, $back, $clean);
    }

    /** Durée couverte par la séquence (ms), comparée au temps réellement écoulé côté serveur. */
    public function spanMs(): int
    {
        return $this->frames[count($this->frames) - 1]['t'] - $this->frames[0]['t'];
    }

    /**
     * Corps de POST /v1/analyze.
     *
     * @param list<string>          $challenge défis tirés par le serveur
     * @param array<string, int|float> $liveness seuils du contrôle du vivant (configuration)
     * @return array<string, mixed>
     */
    public function toRequest(array $challenge, string $referenceDate, array $liveness): array
    {
        return [
            'reference_date' => $referenceDate,
            'document' => ['front' => $this->front, 'back' => $this->back],
            'selfie' => ['challenge' => $challenge, 'frames' => $this->frames],
            'liveness' => $liveness,
        ];
    }

    /** Image en base64 strict : JPEG ou PNG, taille et dimensions bornées (en-tête lu en mémoire). */
    private static function image(mixed $value, int $maxBytes, int $maxSide, int $minSide): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > intdiv($maxBytes * 4, 3) + 4) {
            throw new \InvalidArgumentException('capture_image_invalid');
        }
        $binary = base64_decode($value, true);
        if ($binary === false || strlen($binary) > $maxBytes) {
            throw new \InvalidArgumentException('capture_image_invalid');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $info[0] > $maxSide || $info[1] > $maxSide || $info[0] < $minSide || $info[1] < $minSide) {
            throw new \InvalidArgumentException('capture_image_invalid');
        }

        return $value;
    }
}
