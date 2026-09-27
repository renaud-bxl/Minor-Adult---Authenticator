<?php

declare(strict_types=1);

namespace App\Verification\Biometrics;

/**
 * Réponse du microservice, contrôlée strictement (défense en profondeur) : exactement les six champs du
 * contrat { age, doc_expired, face_match_score, liveness_passed, mrz_valid, reasons }, types et bornes
 * vérifiés, cohérence entre champs. Un champ de trop (un nom, une date…) fait rejeter TOUTE la réponse,
 * sans la journaliser : aucune donnée d'identité ne peut transiter par PHP.
 */
final class AnalysisResult
{
    public const KEYS = ['age', 'doc_expired', 'face_match_score', 'liveness_passed', 'mrz_valid', 'reasons'];

    /** @param list<string> $reasons */
    private function __construct(
        public readonly ?int $age,
        public readonly ?bool $docExpired,
        public readonly ?float $faceMatchScore,
        public readonly bool $livenessPassed,
        public readonly bool $mrzValid,
        public readonly array $reasons,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws BiometricsException réponse hors contrat
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new BiometricsException(BiometricsException::PROTOCOL, 'keys');
        }
        [$age, $expired, $score, $liveness, $mrz, $reasons] = [
            $data['age'], $data['doc_expired'], $data['face_match_score'], $data['liveness_passed'], $data['mrz_valid'], $data['reasons'],
        ];
        $valid = ($age === null || (is_int($age) && $age >= 0 && $age <= 150))
            && ($expired === null || is_bool($expired))
            && ($score === null || ((is_float($score) || is_int($score)) && $score >= -1 && $score <= 1))
            && is_bool($liveness) && is_bool($mrz)
            && is_array($reasons) && array_is_list($reasons) && count($reasons) <= 20
            && array_filter($reasons, static fn (mixed $r): bool => !is_string($r) || preg_match('/^[a-z_]{1,40}$/D', $r) !== 1) === []
            // Cohérence : une MRZ valide donne un âge et une expiration ; sinon ni l'un ni l'autre.
            && ($mrz ? is_int($age) && is_bool($expired) : $age === null && $expired === null);
        if (!$valid) {
            throw new BiometricsException(BiometricsException::PROTOCOL, 'values');
        }

        /** @var list<string> $reasons */
        return new self($age, $expired, $score === null ? null : (float) $score, $liveness, $mrz, $reasons);
    }
}
