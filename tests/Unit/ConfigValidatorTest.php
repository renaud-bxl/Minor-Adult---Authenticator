<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\ConfigValidator;
use App\Core\View;
use App\I18n\Translator;
use App\Services\Mailer;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        $valid = [
            'app' => ['url' => 'https://www.veriage.eu', 'verify_url' => 'https://verify.veriage.eu', 'domain' => 'veriage.eu', 'key' => 'base64:' . base64_encode(str_repeat('a', 32))],
            'security' => ['crypto_key' => 'base64:' . base64_encode(str_repeat('b', 32)), 'session' => ['secure_cookie' => true, 'idle_minutes' => 30]],
            'mail' => ['driver' => 'smtp', 'from_address' => 'no-reply@veriage.eu', 'queue' => 'redis'],
            'verification' => ['allow_private_network_requested' => false],
        ];
        $dir = sys_get_temp_dir() . '/veriage-cfg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($valid as $name => $items) {
            file_put_contents($dir . '/' . $name . '.php', '<?php return ' . var_export($items, true) . ';');
        }
        try {
            return Config::fromDirectory($dir, $overrides);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    public function testValidProductionConfiguration(): void
    {
        self::assertSame([], ConfigValidator::productionProblems($this->config(), false));
    }

    public function testDetectsEachProblemWithoutLeakingSecrets(): void
    {
        $problems = ConfigValidator::productionProblems($this->config([
            'app.url' => 'http://www.veriage.eu',
            'app.domain' => '',
            'app.key' => 'base64:c2hvcnQ=',
            'security.crypto_key' => 'base64:c2hvcnQ=',
            'mail.driver' => 'log',
            'mail.from_address' => 'nope',
            'security.session.secure_cookie' => false,
            'security.session.idle_minutes' => 120,
            'app.verify_url' => 'http://verify.veriage.eu',
            'verification.allow_private_network_requested' => true,
            'mail.queue' => 'files',
        ]), false);
        self::assertCount(12, $problems);
        self::assertContains('VERIFICATION_ALLOW_PRIVATE_NETWORK est interdit en production (SSRF)', $problems);
        self::assertStringNotContainsString('c2hvcnQ', implode(' ', $problems));
    }

    public function testWebRequestsRequirePhpFpm(): void
    {
        $problems = ConfigValidator::productionProblems($this->config(), true);
        self::assertSame(function_exists('fastcgi_finish_request') ? [] : ['PHP-FPM requis (fastcgi_finish_request absent)'], $problems);
    }

    public function testLogMailDriverIsRefusedInProduction(): void
    {
        $this->expectException(\LogicException::class);
        new Mailer(['driver' => 'log'], 'production', sys_get_temp_dir(), new View(sys_get_temp_dir()), new Translator(sys_get_temp_dir(), ['en'], ['site'], 'en'));
    }
}
