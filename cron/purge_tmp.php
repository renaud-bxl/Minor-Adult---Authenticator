<?php

declare(strict_types=1);

/*
 * Purge des fichiers temporaires (toutes les 15 minutes, deploy/crontab) : exigence du cahier des charges
 * (§ 7) pour tout fichier temporaire lié à une vérification.
 *
 * L'application n'écrit AUCUNE image sur disque. Le seul fichier temporaire possible est la copie que PHP
 * fait lui-même d'un corps de requête de plus de 16 Kio (upload_tmp_dir), le temps de la requête : il ne
 * contient qu'un chiffré (AES-256-GCM, CaptureCipher) et PHP le supprime en fin de requête. Ce script
 * supprime ce qui aurait survécu (processus tué) : fichiers de plus de 15 minutes du dossier
 * BIOMETRICS_TMP_DIR (l'upload_tmp_dir du pool PHP-FPM de VeriAge en production, jamais un dossier partagé
 * comme /tmp). Les fichiers sont écrasés avant suppression.
 *
 * Usage : php cron/purge_tmp.php
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$dir = $app->path((string) $app->config->get('biometrics.tmp_dir'));
$maxAge = 60 * (int) $app->config->get('biometrics.tmp_max_age_minutes', 15);
if (!is_dir($dir) || realpath($dir) === false || in_array(realpath($dir), ['/', '/tmp', '/var/tmp'], true)) {
    fwrite(STDERR, "purge_tmp : dossier absent ou partagé, rien à faire\n");
    exit(0);
}
$deleted = 0;
foreach (new DirectoryIterator($dir) as $file) {
    if ($file->isDot() || !$file->isFile() || $file->getFilename() === '.gitkeep' || $file->isLink()) {
        continue;
    }
    if (time() - $file->getMTime() < $maxAge) {
        continue;
    }
    $path = $file->getPathname();
    $size = $file->getSize();
    $handle = @fopen($path, 'r+');
    if ($handle !== false) {
        // Écrasement avant suppression (défense en profondeur ; le contenu était déjà chiffré).
        for ($written = 0; $written < $size; $written += 65536) {
            fwrite($handle, str_repeat("\0", (int) min(65536, $size - $written)));
        }
        fclose($handle);
    }
    if (@unlink($path)) {
        $deleted++;
    }
}
$app->logger()->info('purge_tmp', ['count' => $deleted]);
echo "purge_tmp : {$deleted} fichier(s) supprimé(s)\n";
