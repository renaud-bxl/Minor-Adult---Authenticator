<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Accès MariaDB via PDO : vraies requêtes préparées (pas d'émulation), exceptions, utf8mb4,
 * mode SQL strict et fuseau UTC côté connexion (toutes les dates sont stockées en UTC).
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host: string, port: int, database: string, username: string, password: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
            );
            $pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
                . "SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Exécute une requête d'écriture et renvoie le nombre de lignes affectées.
     *
     * @param array<string|int, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string|int, scalar|null> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Exécute $callback dans une transaction (validée si tout se passe bien, annulée sinon).
     * Un appel imbriqué rejoint simplement la transaction en cours.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string|int, scalar|null> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();

        return $statement;
    }
}
