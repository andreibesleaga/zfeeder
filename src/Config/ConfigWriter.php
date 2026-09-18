<?php

declare(strict_types=1);

namespace Zfeeder\Config;

use Zfeeder\Exception\ConfigException;

/**
 * Saves the configuration as JSON, atomically.
 *
 * 1.6 rewrote a PHP file full of `define()` calls, which is why a config screen
 * bug could execute code. 2.0 writes data only, and never into the web root
 * (`ConfigLoader` refuses a data directory under `public/`).
 *
 * The write is a temporary file in the same directory followed by `rename()`,
 * which is atomic on POSIX, so a reader either sees the old file or the new one
 * and never a half-written config. The `.lock` sibling serialises concurrent
 * admin saves: the caller holds it for the whole read-modify-write.
 */
final class ConfigWriter
{
    private const int DIR_MODE = 0o750;
    private const int FILE_MODE = 0o600;

    /**
     * @throws ConfigException when the directory or the file cannot be written
     */
    public function write(Config $config, string $path): void
    {
        $directory = \dirname($path);
        $this->ensureDirectory($directory);

        try {
            $json = json_encode($config->persistableValues(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException('Cannot encode the configuration: ' . $e->getMessage(), 0, $e);
        }

        $lock = $this->acquireLock($path . '.lock');
        try {
            $this->writeAtomically($path, $json . "\n");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The filesystem calls below are silenced and turned into exceptions: an
     * unwritable data directory is an expected operating condition, and the
     * typed exception carries the path, so PHP's warning would only duplicate
     * the message on its way to the error log.
     *
     * @return resource the locked handle; the caller unlocks and closes it
     *
     * @throws ConfigException
     */
    private function acquireLock(string $lockPath)
    {
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new ConfigException('Cannot open the configuration lock file: ' . $lockPath);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new ConfigException('Cannot lock the configuration file: ' . $lockPath);
        }
        chmod($lockPath, self::FILE_MODE);

        return $handle;
    }

    /** @throws ConfigException */
    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        // Another process may create it between the check and the call; that is
        // the one filesystem race where the failure is not an error.
        if (!@mkdir($directory, self::DIR_MODE, true) && !is_dir($directory)) {
            throw new ConfigException('Cannot create the configuration directory: ' . $directory);
        }
    }

    /** @throws ConfigException */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporary = sprintf('%s.tmp.%s', $path, bin2hex(random_bytes(8)));
        // Silenced for the same reason as the lock: the error is reported as a
        // ConfigException naming the file.
        $written = @file_put_contents($temporary, $contents, LOCK_EX);
        if ($written === false || $written !== \strlen($contents)) {
            @unlink($temporary);

            throw new ConfigException('Cannot write the configuration file: ' . $path);
        }
        if (!@chmod($temporary, self::FILE_MODE) || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConfigException('Cannot replace the configuration file: ' . $path);
        }
    }
}
