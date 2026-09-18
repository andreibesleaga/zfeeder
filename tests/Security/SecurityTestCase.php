<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Config\Config;
use Zfeeder\Kernel;
use Zfeeder\Tests\Integration\Admin\AdminTestCase;
use Zfeeder\Tests\Support\MutableClock;

/**
 * The admin harness of `tests/Integration/Admin/AdminTestCase.php`, reused
 * rather than rebuilt, with the two things a security test needs on top:
 *
 *  - a clock that can be moved, so a login window or an idle session can be
 *    stepped over without sleeping;
 *  - requests from an address of the test's choosing, so "the limit is per
 *    identity" is something that can actually be asserted.
 *
 * Nothing else changes: the same temporary data directory, the same array
 * session, the same mock transport, and still no socket, no PHP session and no
 * wall clock anywhere in the suite.
 */
abstract class SecurityTestCase extends AdminTestCase
{
    protected MutableClock $clock;

    /**
     * The same wiring the parent does, with the fixed clock replaced by a
     * movable one. The defaults are restated rather than inherited because the
     * parent builds its Config and Kernel in one expression.
     *
     * @param array<string, string|int|bool> $overrides
     */
    protected function bootKernel(array $overrides = []): void
    {
        $this->clock = new MutableClock(new \DateTimeImmutable(self::NOW));

        $this->config = Config::forTesting($overrides + [
            'admin_user' => self::USER,
            'admin_password_hash' => self::passwordHash(),
            'allow_private_hosts' => true,
            'default_category' => self::CATEGORY,
        ], $this->tempDir());

        $this->kernel = new Kernel($this->config, fn (): \DateTimeImmutable => $this->clock->now());
    }

    /**
     * A request that appears to come from `$address`, which is what the login
     * limiter keys on.
     *
     * @param array<string, string> $headers
     */
    protected function requestFrom(
        string $method,
        string $path,
        string $address,
        array $headers = [],
    ): ServerRequestInterface {
        return new \Nyholm\Psr7\ServerRequest(
            $method,
            'http://zfeeder.test' . $path,
            $headers,
            null,
            '1.1',
            ['REMOTE_ADDR' => $address],
        );
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function sendFrom(
        string $method,
        string $path,
        string $address,
        array $body = [],
        array $headers = [],
    ): ResponseInterface {
        $request = $this->requestFrom($method, $path, $address, $headers);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        return $this->dispatch($request);
    }

    /**
     * Every path the router answers a POST on, taken from the router itself so
     * that a route added later is covered here without anyone remembering to
     * add it.
     *
     * @return list<string>
     */
    protected function postPathsFromRouter(): array
    {
        $paths = [];
        foreach ($this->router->routes() as $route) {
            if (in_array('POST', $route->methods, true)) {
                $paths[] = $route->pattern;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * A fingerprint of everything under the data directory: path, size and
     * contents hash. Comparing two of these proves "nothing changed on disk"
     * without naming the files in advance.
     *
     * @return array<string, string>
     */
    protected function dataDirectoryFingerprint(): array
    {
        $root = $this->config->dataDir();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $path = $entry->getPathname();
            $relative = substr($path, strlen($root));
            $out[$relative] = $entry->isFile()
                ? hash_file('sha256', $path) . ':' . (string) $entry->getSize()
                : 'dir';
        }
        ksort($out);

        return $out;
    }

    /**
     * Every `.php` file under `src/`, for the tests that assert a construct is
     * absent from the whole tree.
     *
     * @return list<string>
     */
    protected static function sourceFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    protected static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Cannot read ' . $path);

        return $contents;
    }
}
