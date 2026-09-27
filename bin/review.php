<?php

declare(strict_types=1);

/*
 * File de vérification manuelle (phase 3 : sans interface ; l'administration viendra en phase 7).
 *
 *   php bin/review.php list                 revues en attente (aucune donnée d'identité)
 *   php bin/review.php approve --id=12      confirme le résultat d'âge provisoire (webhook envoyé)
 *   php bin/review.php reject  --id=12      échec « manual_review_rejected » (webhook envoyé)
 *
 * Une revue ne contient que les signaux de la décision automatique (score de correspondance, contrôle
 * du vivant, motifs codés) : aucune image n'est conservée. ⚖️ Conserver des images pour une revue
 * humaine demanderait une décision juridique (durée, chiffrement, base légale) : voir docs/rgpd.md.
 */

use App\Models\ManualReviewRepository;
use App\Verification\VerificationService;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$command = $argv[1] ?? 'help';
$id = null;
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--id=(\d{1,18})$/D', $argument, $m) === 1) {
        $id = (int) $m[1];
    }
}

switch ($command) {
    case 'list':
        $rows = (new ManualReviewRepository($app->db()))->pending();
        if ($rows === []) {
            echo "Aucune revue en attente.\n";
        }
        foreach ($rows as $row) {
            printf("#%d  session %s  projet %d  %s  score %s  vivant %s  âge ≥ %d : %s  motifs [%s]  créée %s UTC  expire %s UTC\n",
                $row['id'], $row['public_id'], $row['project_id'], (int) $row['livemode'] === 1 ? 'live' : 'test',
                $row['face_match_score'] ?? '-', (int) $row['liveness_passed'] === 1 ? 'oui' : 'non', $row['min_age'],
                (int) $row['provisional_is_adult'] === 1 ? 'oui' : 'non',
                implode(',', (array) json_decode((string) $row['reasons'], true)), $row['created_at'], $row['expires_at']);
        }
        exit(0);

    case 'approve':
    case 'reject':
        if ($id === null) {
            fwrite(STDERR, "--id=… requis.\n");
            exit(1);
        }
        $done = VerificationService::fromApplication($app)->decideReview($id, $command === 'approve');
        echo $done ? "Décision enregistrée.\n" : "Revue introuvable, déjà décidée ou expirée.\n";
        exit($done ? 0 : 1);

    default:
        fwrite(STDERR, "Commandes : list, approve --id=…, reject --id=… (voir l'en-tête de bin/review.php).\n");
        exit($command === 'help' ? 0 : 1);
}
