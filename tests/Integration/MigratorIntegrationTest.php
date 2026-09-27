<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;

final class MigratorIntegrationTest extends IntegrationTestCase
{
    public function testMigratesFromScratchAndIsIdempotent(): void
    {
        self::dropAllTables($this->app);
        $migrator = new Migrator($this->app->db(), $this->app->path('database/migrations'));

        $applied = $migrator->migrate();
        self::assertSame($migrator->files(), $applied);
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate(), 'second passage : rien à faire');

        $tables = $this->app->db()->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['migrations', 'accounts', 'users', 'account_users', 'user_tokens', 'audit_log'] as $table) {
            self::assertContains($table, $tables);
        }
        $count = $this->app->db()->fetchOne('SELECT COUNT(*) AS n FROM migrations');
        self::assertSame(count($applied), (int) $count['n']);
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $dir = sys_get_temp_dir() . '/veriage-bad-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0001_broken.sql', 'CREATE TABLE tmp_broken (id INT NOT A VALID TYPE)');
        try {
            $migrator = new Migrator($this->app->db(), $dir);
            try {
                $migrator->migrate();
                self::fail('Exception attendue');
            } catch (\PDOException) {
                self::assertSame(['0001_broken.sql'], $migrator->pending());
            }
        } finally {
            unlink($dir . '/0001_broken.sql');
            rmdir($dir);
        }
    }
}
