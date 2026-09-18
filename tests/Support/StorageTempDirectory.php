<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

/**
 * A scratch directory per test, removed afterwards.
 *
 * Tests never touch the repository's `data/` directory: every store under test
 * is pointed at a fresh directory under the system temporary directory, so a
 * failing test cannot leave state behind for the next one.
 */
trait StorageTempDirectory
{
    private ?string $temporaryDirectory = null;

    /** Creates the directory on first use. */
    protected function tempDir(): string
    {
        if ($this->temporaryDirectory === null) {
            $path = sys_get_temp_dir() . '/zfeeder-test-' . bin2hex(random_bytes(6));
            mkdir($path, 0o750, true);
            $this->temporaryDirectory = $path;
        }

        return $this->temporaryDirectory;
    }

    protected function tempPath(string $relative): string
    {
        return $this->tempDir() . '/' . ltrim($relative, '/');
    }

    protected function removeTempDir(): void
    {
        if ($this->temporaryDirectory !== null) {
            self::removeTree($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }
    }

    protected static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
