<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Verification\Biometrics\BiometricsTransport;

/** Application configurée pour les tests : journaux et boîte d'envoi dans un dossier temporaire. */
final class TestApplication
{
    /** Faux microservice biométrique injecté dans chaque application de test (null : transport réel). */
    public static ?BiometricsTransport $biometrics = null;

    public static function directory(): string
    {
        $dir = sys_get_temp_dir() . '/veriage-tests-' . getmypid();
        foreach (['logs', 'mail'] as $sub) {
            if (!is_dir($dir . '/' . $sub)) {
                mkdir($dir . '/' . $sub, 0700, true);
            }
        }

        return $dir;
    }

    /** @param array<string, mixed> $overrides surcharges de configuration (notation pointée) */
    public static function boot(array $overrides = []): Application
    {
        $dir = self::directory();

        $app = Application::boot(dirname(__DIR__, 2), [
            'app.log_path' => $dir . '/logs',
            'mail.outbox' => $dir . '/mail',
            ...$overrides,
        ]);
        if (self::$biometrics !== null) {
            $app->setBiometricsTransport(self::$biometrics);
        }

        return $app;
    }

    public static function clearOutbox(): void
    {
        array_map('unlink', glob(self::directory() . '/mail/*.eml') ?: []);
    }

    /** @return list<string> messages MIME décodés (quoted-printable), du plus ancien au plus récent */
    public static function outbox(): array
    {
        $files = glob(self::directory() . '/mail/*.eml') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $f): string => quoted_printable_decode((string) file_get_contents($f)), $files);
    }

    /** @return list<string> journaux applicatifs produits pendant les tests */
    public static function logLines(): array
    {
        $lines = [];
        foreach (glob(self::directory() . '/logs/*.log') ?: [] as $file) {
            array_push($lines, ...(file($file, FILE_IGNORE_NEW_LINES) ?: []));
        }

        return $lines;
    }
}
