<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed readers for Console's `mixed` option and argument values.
 *
 * `InputInterface::getOption()` returns `mixed`, so every command would
 * otherwise repeat the same `is_string()` dance eleven times over. Keeping the
 * narrowing in one place is what lets the commands themselves stay readable at
 * PHPStan level 8.
 */
trait CommandInput
{
    /** A `--name=value` option, trimmed, or null when it was not given. */
    private function option(InputInterface $input, string $name): ?string
    {
        /** @var mixed $value */
        $value = $input->getOption($name);
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** A `--name` switch. */
    private function flag(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }

    /** A `--name=123` option, or the fallback when it is absent or not a number. */
    private function intOption(InputInterface $input, string $name, int $fallback): int
    {
        /** @var mixed $value */
        $value = $input->getOption($name);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $fallback;
    }

    /** A positional argument, trimmed; the empty string when it was not given. */
    private function argumentString(InputInterface $input, string $name): string
    {
        /** @var mixed $value */
        $value = $input->getArgument($name);

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * Machine readable output. Pretty printed and with unescaped slashes so
     * that a person reading a bug report can follow it without a JSON tool.
     */
    private function json(mixed $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Every payload here is built from scalars, so this cannot happen;
            // returning a valid document beats throwing out of a report.
            return '{"error":' . json_encode($e->getMessage()) . '}';
        }
    }

    /** Bytes as something a person can read: 1536 becomes "1.5 KiB". */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 'KiB';
        foreach ($units as $candidate) {
            $value /= 1024.0;
            $unit = $candidate;
            if ($value < 1024.0) {
                break;
            }
        }

        return sprintf('%.1f %s', $value, $unit);
    }
}
