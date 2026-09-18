<?php

declare(strict_types=1);

namespace Zfeeder\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Minimal PSR-3 logger: one line per record, to a file or to a stream.
 *
 * Writing to `php://stdout` keeps the container 12-factor; writing to a file
 * keeps shared hosting workable. Nothing else is needed at this size.
 */
final class FileLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private int $threshold;

    public function __construct(
        private readonly string $path,
        string $minimumLevel = LogLevel::WARNING,
    ) {
        $this->threshold = self::LEVELS[$minimumLevel] ?? 3;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $levelName = is_string($level) ? $level : LogLevel::INFO;
        if ((self::LEVELS[$levelName] ?? 1) < $this->threshold) {
            return;
        }

        $line = sprintf(
            "%s %s %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($levelName),
            $this->interpolate((string) $message, $context),
            $context === [] ? '' : ' ' . $this->encodeContext($context),
        );

        $handle = @fopen($this->path, 'a');
        if ($handle === false) {
            return;
        }
        @flock($handle, LOCK_EX);
        @fwrite($handle, $line);
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @param array<string, mixed> $context */
    private function encodeContext(array $context): string
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $safe[$key] = $value::class . ': ' . $value->getMessage();
                continue;
            }
            $safe[$key] = is_scalar($value) || $value === null ? $value : gettype($value);
        }

        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }
}
