<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/veriage-translator-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        @rmdir($this->logDir);
    }

    private function translator(array $enabled = ['fr', 'en', 'de']): Translator
    {
        return new Translator(dirname(__DIR__) . '/Fixtures/lang', $enabled, ['site', 'module'], 'en', new Logger($this->logDir, 'debug'));
    }

    public function testTranslatesWithParameters(): void
    {
        $t = $this->translator();
        $t->setLocale('fr');
        self::assertSame('Bonjour Renaud', $t->get('site.greeting.hello', ['name' => 'Renaud']));
        self::assertSame('Hallo Ada', $t->get('site.greeting.hello', ['name' => 'Ada'], 'de'));
    }

    public function testParametersAreNotRecursivelyReplaced(): void
    {
        $t = $this->translator();
        self::assertSame('Hello {name}', $t->get('site.greeting.hello', ['name' => '{name}']));
    }

    public function testFallsBackToEnglishThenToKey(): void
    {
        $t = $this->translator();
        $t->setLocale('de');
        self::assertSame('Only in English', $t->get('site.only.en'));
        self::assertSame('site.does.not_exist', $t->get('site.does.not_exist'));
        // Une clé présente seulement en FR n'est pas servie en DE : la chaîne de repli est DE → EN → clé.
        self::assertSame('site.only.fr', $t->get('site.only.fr'));
    }

    public function testMissingKeyIsLoggedOnce(): void
    {
        $t = $this->translator();
        $t->get('site.missing.key');
        $t->get('site.missing.key');
        $lines = file((string) glob($this->logDir . '/*.log')[0], FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $lines);
        self::assertStringContainsString('"key":"site.missing.key"', $lines[0]);
    }

    public function testUnknownDomainReturnsKey(): void
    {
        self::assertSame('nope.greeting.hello', $this->translator()->get('nope.greeting.hello'));
    }

    public function testRejectsDisabledLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->translator(['fr', 'en'])->setLocale('de');
    }

    public function testFallbackMustBeEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Translator(dirname(__DIR__) . '/Fixtures/lang', ['fr'], ['site'], 'en');
    }

    public function testDomainExportIsCompletedByFallback(): void
    {
        $messages = $this->translator()->domain('site', 'de');
        self::assertSame(['greeting.hello' => 'Hallo {name}', 'only.en' => 'Only in English'], $messages);
    }

    public function testDomainExportRejectsUnknownDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->translator()->domain('secrets', 'fr');
    }
}
