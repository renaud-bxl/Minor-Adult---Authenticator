<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Erreur de l'API rendue en JSON normalisé :
 * { "error": { "code": "…", "message": "…", "details": { champ: code } } }.
 *
 * Le code est stable (contrat de l'API, en anglais, documenté) ; le message est traduit
 * (clé « errors.api.{code} ») selon Accept-Language, à titre indicatif pour le développeur.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, string> $details erreurs par champ (codes stables), ex. ['email' => 'invalid']
     * @param array<string, string> $headers en-têtes à ajouter (Retry-After, WWW-Authenticate…)
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        private readonly array $details = [],
        private readonly array $headers = [],
    ) {
        parent::__construct('API ' . $status . ' ' . $errorCode);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
