<?php

declare(strict_types=1);

namespace Zfeeder\Storage;

use Zfeeder\Exception\StorageException;

/**
 * Crash-safe file writing for the flat-file stores.
 *
 * Every write goes to a uniquely named temporary file in the *same* directory
 * and is then `rename()`d over the target. Rename within a filesystem is
 * atomic, so a concurrent reader sees either the previous file or the complete
 * new one — never the truncated middle that 1.6's `fopen('w')` could leave
 * behind when a request was aborted mid-save.
 *
 * An exclusive lock on a `.lock` sibling serialises writers, so two admin tabs
 * saving the same category cannot interleave.
 */
final class AtomicFile
{
    public const int DIR_MODE = 0o750;
    public const int FILE_MODE = 0o640;

    /** @throws StorageException */
    public static function ensureDirectory(string $directory, int $mode = self::DIR_MODE): void
    {
        if (is_dir($directory)) {
            return;
        }
        // A parallel request may win the race; that is the one expected failure.
        if (!@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new StorageException('Cannot create directory: ' . $directory);
        }
    }

    /** @throws StorageException */
    public static function write(string $path, string $contents, int $mode = self::FILE_MODE): void
    {
        $directory = \dirname($path);
        self::ensureDirectory($directory);

        $lock = self::lock($path . '.lock');
        try {
            $temporary = sprintf('%s.tmp.%s', $path, bin2hex(random_bytes(8)));
            $written = @file_put_contents($temporary, $contents);
            if ($written === false || $written !== \strlen($contents)) {
                @unlink($temporary);

                throw new StorageException('Cannot write file: ' . $path);
            }
            if (!@chmod($temporary, $mode) || !@rename($temporary, $path)) {
                @unlink($temporary);

                throw new StorageException('Cannot replace file: ' . $path);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @throws StorageException */
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new StorageException('Cannot read file: ' . $path);
        }

        return $contents;
    }

    /** @return bool true when the file existed and is now gone */
    public static function delete(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * Silenced and rethrown: an unwritable data directory is an expected
     * operating condition, and the StorageException already names the path.
     *
     * @return resource
     *
     * @throws StorageException
     */
    private static function lock(string $lockPath)
    {
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new StorageException('Cannot open lock file: ' . $lockPath);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new StorageException('Cannot lock file: ' . $lockPath);
        }

        return $handle;
    }
}
