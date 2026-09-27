<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerRetentionTest extends TestCase
{
    public function testDailyRotationPurgesFilesOlderThanRetention(): void
    {
        $dir = sys_get_temp_dir() . '/veriage-logs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $old = $dir . '/app-' . gmdate('Y-m-d', time() - 31 * 86400) . '.log';
        $kept = $dir . '/app-' . gmdate('Y-m-d', time() - 29 * 86400) . '.log';
        $other = $dir . '/unrelated.txt';
        foreach ([$old, $kept, $other] as $file) {
            touch($file);
        }

        (new Logger($dir, 'info', 30))->info('first_line_of_the_day');

        self::assertFileDoesNotExist($old);
        self::assertFileExists($kept);
        self::assertFileExists($other);
        self::assertFileExists($dir . '/app-' . gmdate('Y-m-d') . '.log');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
