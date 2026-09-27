<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journal applicatif au format JSON Lines, un fichier par jour.
 *
 * RGPD : les appelants ne doivent jamais transmettre de donnée personnelle. Par défense en profondeur,
 * toute adresse e-mail et toute adresse IP présentes dans le message ou le contexte sont masquées.
 */
final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const IPV4_PATTERN = '/\b(?:\d{1,3}\.){3}\d{1,3}\b/';
    // Candidats IPv6 (formes compressées comprises), confirmés ensuite par filter_var.
    private const IPV6_PATTERN = '/(?<![\w:.])[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}(?![\w:.])/i';

    private readonly int $threshold;

    public function __construct(private readonly string $directory, string $minLevel = 'info')
    {
        $this->threshold = self::LEVELS[$minLevel] ?? self::LEVELS['info'];
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? PHP_INT_MAX) < $this->threshold) {
            return;
        }

        $line = json_encode([
            'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'level' => $level,
            'message' => self::redact($message),
            'context' => self::redactArray($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
        // En cas d'échec d'écriture, on ne masque pas l'incident : il remonte dans le journal d'erreurs PHP.
        $file = $this->directory . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('VeriAge: impossible d\'écrire dans ' . $file);
        }
    }

    /**
     * Contexte sûr pour journaliser une exception : classe, fichier, ligne et pile sans arguments.
     * Le message des PDOException est écarté, car il peut contenir des valeurs (ex. e-mail en doublon).
     *
     * @return array<string, mixed>
     */
    public static function exceptionContext(\Throwable $e): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 15) as $frame) {
            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')
                . ' @ ' . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
        }

        return [
            'exception' => $e::class,
            'message' => $e instanceof \PDOException ? 'SQLSTATE ' . (string) $e->getCode() : $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $frames,
            'previous' => $e->getPrevious() !== null ? $e->getPrevious()::class : null,
        ];
    }

    public static function redact(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL_PATTERN, '[email]', $value);
        $value = (string) preg_replace(self::IPV4_PATTERN, '[ip]', $value);

        return (string) preg_replace_callback(
            self::IPV6_PATTERN,
            static fn (array $m): string => preg_match('/[0-9a-f]/i', $m[0]) === 1
                && filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[ip]' : $m[0],
            $value,
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redactArray($value);
            }
        }

        return $values;
    }
}
