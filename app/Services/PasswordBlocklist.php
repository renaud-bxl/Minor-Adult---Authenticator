<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Liste locale de mots de passe courants ou compromis (aucun appel externe à l'exécution).
 *
 * Fichier : resources/security/common-passwords.txt, une entrée par ligne, en minuscules (NFC),
 * triée par octets. La recherche est dichotomique directement dans le fichier : ~18 lectures,
 * aucune liste chargée en mémoire. Source et régénération : tasks/todo.md (phase 1).
 */
final class PasswordBlocklist
{
    public function __construct(private readonly string $file)
    {
        if (!is_readable($file)) {
            throw new \RuntimeException('Liste de mots de passe courants introuvable : ' . $file);
        }
    }

    /** @param string $candidate valeur déjà normalisée (minuscules, NFC) */
    public function contains(string $candidate): bool
    {
        if ($candidate === '' || str_contains($candidate, "\n")) {
            return false;
        }
        $handle = fopen($this->file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Lecture impossible : ' . $this->file);
        }

        try {
            // Invariant : si l'entrée existe, sa ligne commence dans [low, high).
            $low = 0;
            $high = (int) filesize($this->file);
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                fseek($handle, max(0, $mid - 1));
                if ($mid > 0) {
                    fgets($handle); // Aller au début de la première ligne commençant à mid ou après.
                }
                $start = (int) ftell($handle);
                $line = $start < $high ? fgets($handle) : false;
                if ($line === false) {
                    $high = $mid;
                    continue;
                }
                $comparison = strcmp(rtrim($line, "\n"), $candidate);
                if ($comparison === 0) {
                    return true;
                }
                if ($comparison < 0) {
                    $low = $start + strlen($line);
                } else {
                    $high = $mid;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}
