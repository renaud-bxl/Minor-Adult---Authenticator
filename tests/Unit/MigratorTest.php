<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/veriage-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
    }

    private function migrator(): Migrator
    {
        // Aucune connexion n'est ouverte tant qu'aucune requête n'est exécutée.
        return new Migrator(new Database(['host' => 'none', 'port' => 0, 'database' => '', 'username' => '', 'password' => '']), $this->dir);
    }

    public function testSplitsStatementsOutsideQuotesAndComments(): void
    {
        $sql = <<<'SQL'
            -- commentaire ; ignoré
            CREATE TABLE a (id INT); # autre ; commentaire
            INSERT INTO a VALUES ('x;y'), ("q\";z");
            /* bloc ; commentaire */ INSERT INTO `we;ird` VALUES (1);
            SQL;
        $statements = Migrator::splitStatements($sql);
        self::assertCount(3, $statements);
        self::assertSame('CREATE TABLE a (id INT)', $statements[0]);
        self::assertSame("INSERT INTO a VALUES ('x;y'), (\"q\\\";z\")", $statements[1]);
        self::assertSame('INSERT INTO `we;ird` VALUES (1)', $statements[2]);
    }

    public function testEmptyScriptHasNoStatement(): void
    {
        self::assertSame([], Migrator::splitStatements("-- rien\n  ;  \n"));
    }

    public function testFilesAreSortedAndHiddenFilesIgnored(): void
    {
        touch($this->dir . '/0002_b.sql');
        touch($this->dir . '/0001_a.sql');
        touch($this->dir . '/0010_c.sql');
        touch($this->dir . '/.gitkeep');
        self::assertSame(['0001_a.sql', '0002_b.sql', '0010_c.sql'], $this->migrator()->files());
    }

    public function testRejectsBadNames(): void
    {
        touch($this->dir . '/create_things.sql');
        $this->expectException(\RuntimeException::class);
        $this->migrator()->files();
    }

    public function testRejectsDuplicateNumbers(): void
    {
        touch($this->dir . '/0001_a.sql');
        touch($this->dir . '/0001_b.sql');
        $this->expectExceptionMessage('0001');
        $this->migrator()->files();
    }

    public function testRealMigrationsAreWellFormed(): void
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $files = (new Migrator(new Database(['host' => 'none', 'port' => 0, 'database' => '', 'username' => '', 'password' => '']), $dir))->files();
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            // Règle du projet : un seul DDL par migration (voir Migrator).
            self::assertCount(1, Migrator::splitStatements((string) file_get_contents($dir . '/' . $file)), $file);
        }
    }
}
