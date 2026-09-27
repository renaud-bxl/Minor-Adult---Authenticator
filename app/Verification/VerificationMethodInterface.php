<?php

declare(strict_types=1);

namespace App\Verification;

use App\Models\VerificationSession;

/**
 * Méthode de vérification d'âge (adaptateur). Implémentations : MockProvider (sandbox, phase 2),
 * LocalBiometricsProvider (document + visage, phase 3), eID belge (phase 4). Aucun fournisseur
 * externe (CLAUDE.md).
 *
 * Contrat RGPD : une méthode ne renvoie que le résultat (VerificationOutcome) ; elle ne conserve ni
 * ne journalise aucune donnée d'identité (image, nom, numéro, date de naissance).
 */
interface VerificationMethodInterface
{
    /** Identifiant stable, renvoyé aux clients dans « method » (ex. « mock », « id_document_face »). */
    public function id(): string;

    /** Méthode proposable pour ce mode (sandbox ou production). */
    public function isAvailable(bool $livemode): bool;

    /**
     * La méthode doit s'exécuter dans une fenêtre de premier niveau (popup ou redirection), et non dans
     * une iframe : c'est le cas de l'eID (certificat client TLS peu fiable dans un cadre).
     */
    public function requiresTopLevelWindow(): bool;

    /**
     * Exécute la vérification pour la session (adresse déjà contrôlée, consentement recueilli).
     *
     * @param array<string, string> $input données du formulaire de la méthode
     *
     * @throws \InvalidArgumentException si l'entrée est invalide (erreur de saisie, 422)
     */
    public function verify(VerificationSession $session, array $input): VerificationOutcome;
}
