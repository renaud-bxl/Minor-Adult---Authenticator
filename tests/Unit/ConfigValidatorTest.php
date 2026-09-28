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
            'security' => ['crypto_key' => 'base64:' . base64_encode(str_repeat('b', 32)), 'session' => ['secure_cookie' => true, 'idle_minutes' => 30],
                'rate_limits' => ['verify_page_ip' => [600, 60], 'verify_code_ip' => [300, 3600], 'verify_code_send_ip' => [100, 3600], 'verify_code_global' => [20, 86400]]],
            'mail' => ['driver' => 'smtp', 'from_address' => 'no-reply@veriage.eu', 'queue' => 'redis'],
            'verification' => ['allow_private_network_requested' => false, 'default_negative_ttl_hours' => 24, 'return_token_window' => 600],
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

    public function testCryptoKeyringAndDemoKeyAreChecked(): void
    {
        $current = 'base64:' . base64_encode(str_repeat('b', 32));
        $old = 'base64:' . base64_encode(str_repeat('c', 32));
        self::assertSame([], ConfigValidator::productionProblems($this->config([
            'security.crypto_key_version' => 2, 'security.crypto_previous_keys' => '1:' . $old,
            'app.demo_enabled' => true, 'app.demo_api_key' => 'sk_test_' . str_repeat('a', 40),
        ]), false));
        $invalid = [
            ['security.crypto_key_version' => 256],
            ['security.crypto_key_version' => 2, 'security.crypto_previous_keys' => '2:' . $old],
            ['security.crypto_previous_keys' => 'nope'],
            ['security.crypto_key_version' => 2, 'security.crypto_previous_keys' => '1:' . $current],
            ['app.demo_enabled' => true, 'app.demo_api_key' => 'sk_live_' . str_repeat('a', 40)],
        ];
        foreach ($invalid as $overrides) {
            $problems = ConfigValidator::productionProblems($this->config($overrides), false);
            self::assertCount(1, $problems, json_encode($overrides, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString(substr($old, 7, 10), $problems[0], 'aucune clé dans le message');
        }
    }

    public function testModuleLimitsFromTheEnvironmentAreChecked(): void
    {
        // Valeur non numérique dans .env : « (int) » la lit 0, ce qui bloquerait toutes les pages (limite nulle).
        $problems = ConfigValidator::productionProblems($this->config([
            'security.rate_limits.verify_page_ip' => [0, 60],
            'security.rate_limits.verify_code_ip' => [20_000, 3600],
            'security.rate_limits.verify_code_send_ip' => [0, 3600],
            'security.rate_limits.verify_code_global' => [0, 86400],
            'verification.default_negative_ttl_hours' => 721,
            'verification.return_token_window' => 86400,
        ]), false);
        self::assertSame([
            'RATE_VERIFY_PAGE_IP_PER_MINUTE doit être un entier de 1 à 10000',
            'RATE_VERIFY_CODE_IP_PER_HOUR doit être un entier de 1 à 10000',
            'RATE_VERIFY_CODE_SEND_IP_PER_HOUR doit être un entier de 1 à 10000',
            'VERIFICATION_GLOBAL_CODE_FAILURES doit être un entier de 1 à 1000',
            'VERIFICATION_NEGATIVE_TTL_HOURS doit être compris entre 0 et 720',
            'VERIFICATION_RETURN_TOKEN_WINDOW doit être compris entre 60 et 3600 secondes',
        ], $problems);
        // La configuration réellement livrée (valeurs par défaut) est valide.
        $security = require dirname(__DIR__, 2) . '/config/security.php';
        $verification = require dirname(__DIR__, 2) . '/config/verification.php';
        self::assertSame([], ConfigValidator::productionProblems($this->config([
            'security.rate_limits' => $security['rate_limits'],
            'verification.default_negative_ttl_hours' => $verification['default_negative_ttl_hours'],
            'verification.return_token_window' => $verification['return_token_window'],
        ]), false));
    }

    public function testBiometricsSettingsAreChecked(): void
    {
        $shipped = require dirname(__DIR__, 2) . '/config/biometrics.php';
        $valid = [...$shipped, 'enabled' => true, 'secret' => str_repeat('s', 64)];
        self::assertSame([], ConfigValidator::biometricsProblems($this->config(['biometrics' => $valid])), 'valeurs livrées valides');
        self::assertSame([], ConfigValidator::biometricsProblems($this->config(['biometrics' => [...$valid, 'enabled' => false, 'secret' => '']])), 'désactivée : rien à contrôler');
        $problems = ConfigValidator::biometricsProblems($this->config(['biometrics' => [...$valid,
            'secret' => 'court', 'url' => 'http://10.0.0.5:8765', 'face_match_threshold' => 0.30, 'face_review_threshold' => 0.20,
            'below_threshold_default' => 'accept', 'timeout' => 300, 'tmp_dir' => 'public/tmp',
            'liveness' => [...$shipped['liveness'], 'yaw_threshold' => 0.05],
        ]]));
        self::assertCount(7, $problems);
        self::assertStringContainsString('acceptation ≥ 0,363', implode("\n", $problems), 'plancher SFace');
        self::assertStringNotContainsString('court', implode("\n", $problems), 'aucun secret dans les messages');
        // Revue manuelle désactivée en V1 (audit phase 3, E3) : « review » refusé au démarrage.
        $review = ConfigValidator::biometricsProblems($this->config(['biometrics' => [...$valid, 'below_threshold_default' => 'review']]));
        self::assertSame(['BIOMETRICS_BELOW_THRESHOLD doit valoir fail (revue manuelle désactivée en V1)'], $review);
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
