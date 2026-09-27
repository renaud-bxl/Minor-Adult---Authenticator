<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applique dans l'ordre les fichiers database/migrations/NNNN_nom.sql non encore appliqués.
 *
 * Chaque migration s'exécute dans une transaction et n'est inscrite dans la table `migrations`
 * qu'en cas de succès. Attention : MariaDB valide implicitement toute instruction DDL (CREATE,
 * ALTER…) ; la transaction ne protège donc que le DML. Règle du projet : une migration = un
 * changement de schéma cohérent (idéalement une seule instruction DDL), pour qu'un échec ne laisse
 * jamais de schéma à moitié appliqué. Un verrou nommé empêche deux exécutions simultanées.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^(\d{4})_[a-z0-9_]+\.sql$/';
    private const LOCK_NAME = 'veriage_migrations';

    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /**
     * Fichiers de migration triés, après contrôle du nommage et de l'unicité des numéros.
     *
     * @return list<string> noms de fichiers
     */
    public function files(): array
    {
        $files = [];
        $numbers = [];
        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
                continue;
            }
            if (preg_match(self::FILE_PATTERN, $file, $m) !== 1) {
                throw new \RuntimeException('Nom de migration invalide : ' . $file . ' (attendu : NNNN_nom.sql)');
            }
            if (isset($numbers[$m[1]])) {
                throw new \RuntimeException('Numéro de migration en double : ' . $m[1]);
            }
            $numbers[$m[1]] = true;
            $files[] = $file;
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> migrations en attente */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = array_column($this->db->fetchAll('SELECT migration FROM migrations'), 'migration');

        return array_values(array_diff($this->files(), $applied));
    }

    /** @return list<string> migrations appliquées lors de cet appel */
    public function migrate(): array
    {
        $this->ensureTable();
        $lock = $this->db->fetchOne('SELECT GET_LOCK(?, 30) AS acquired', [self::LOCK_NAME]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Une autre exécution des migrations est en cours.');
        }

        try {
            $done = [];
            foreach ($this->pending() as $file) {
                $sql = (string) file_get_contents($this->directory . '/' . $file);
                $statements = self::splitStatements($sql);
                if ($statements === []) {
                    throw new \RuntimeException('Migration vide : ' . $file);
                }
                $this->db->transaction(function (Database $db) use ($statements, $file): void {
                    foreach ($statements as $statement) {
                        $db->pdo()->exec($statement);
                    }
                    $db->execute('INSERT INTO migrations (migration, applied_at) VALUES (?, UTC_TIMESTAMP())', [$file]);
                });
                $done[] = $file;
            }

            return $done;
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }

    /**
     * Découpe un script SQL en instructions sur les « ; » situés hors chaînes, identifiants et commentaires.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if (($char === '-' && $next === '-') || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }
            if ($char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $statements[] = trim($current);

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' migration VARCHAR(190) NOT NULL,'
            . ' applied_at DATETIME NOT NULL,'
            . ' UNIQUE KEY uq_migrations_migration (migration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
