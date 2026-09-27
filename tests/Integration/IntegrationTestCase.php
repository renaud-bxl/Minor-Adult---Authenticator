<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

/**
 * Base des tests d'intégration : base MariaDB « veriage_test » recréée par les migrations au début
 * de la suite, tables vidées et Redis (base 15, dédiée aux tests) purgé avant chaque test.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected Application $app;

    protected function setUp(): void
    {
        $this->app = TestApplication::boot();
        $db = $this->app->db();
        if ((string) $this->app->config->get('database.database') !== 'veriage_test') {
            self::fail('Les tests d\'intégration exigent la base veriage_test.');
        }

        if (!self::$migrated) {
            self::dropAllTables($this->app);
            (new Migrator($db, $this->app->path('database/migrations')))->migrate();
            self::$migrated = true;
        }

        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['audit_log', 'user_tokens', 'account_users', 'users', 'accounts'] as $table) {
            $db->pdo()->exec('TRUNCATE TABLE ' . $table);
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->app->redis()->flushdb();
        TestApplication::clearOutbox();
    }

    public static function dropAllTables(Application $app): void
    {
        $pdo = $app->db()->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
