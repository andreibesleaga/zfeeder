<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Zfeeder\Config\Config;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Exception\TemplateException;
use Zfeeder\Http\ErrorHandler;
use Zfeeder\Tests\Support\RecordingLogger;

/**
 * S16 — in production an error page carries a status and a sentence: no file
 * path, no class name, no stack frame and no configuration value. In
 * development the detail is there, because that is what it is for. A 404 and a
 * 405 are plain either way, and the operator gets the detail in the log.
 *
 * Closes 1.6 defect L17: errors were suppressed rather than handled —
 * `error_reporting(E_ALL ^ E_NOTICE)` at `newsfeeds/admin.php:21` and `@` on
 * every file operation — so a failure was either invisible or printed with its
 * full path into the page.
 *
 * The control lives in `src/Http/ErrorHandler.php` (detail only when
 * `Config::isProduction()` is false) and in `public/index.php`, whose boot
 * failure branch prints one sentence and sends the rest to `error_log()`.
 */
final class S16ErrorHandlerTest extends SecurityTestCase
{
    private const string SECRET_PATH = '/srv/private/zfeeder/data/config.json';
    private const string SECRET_VALUE = 'argon2id-hash-nobody-should-see';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    private function handler(string $environment): ErrorHandler
    {
        return new ErrorHandler(
            Config::forTesting(['env' => $environment], $this->tempDir()),
            $this->logger,
        );
    }

    /** An exception whose message is exactly the kind of thing that must not leak. */
    private static function leakyException(): \Throwable
    {
        return new ConfigException(
            'Cannot write ' . self::SECRET_PATH . ': admin_password_hash=' . self::SECRET_VALUE,
        );
    }

    /** @return iterable<string, array{\Throwable, int}> */
    public static function exceptions(): iterable
    {
        yield 'security' => [new SecurityException('Refusing to fetch ' . self::SECRET_PATH), 400];
        yield 'template' => [new TemplateException('Missing template ' . self::SECRET_PATH), 404];
        yield 'config' => [self::leakyException(), 500];
        yield 'storage' => [new StorageException('Cannot read ' . self::SECRET_PATH), 500];
        yield 'runtime' => [new \RuntimeException('Broke at ' . self::SECRET_PATH), 500];
    }

    #[DataProvider('exceptions')]
    public function testProductionLeaksNoPathClassNameOrStackFrame(\Throwable $error, int $expectedStatus): void
    {
        $response = $this->handler('production')->handle($error);
        $body = self::bodyOf($response);

        self::assertSame($expectedStatus, $response->getStatusCode());

        self::assertStringNotContainsString(self::SECRET_PATH, $body, 'a file path is in the error page');
        self::assertStringNotContainsString(self::SECRET_VALUE, $body, 'a configuration value is in the error page');
        self::assertStringNotContainsString($error->getMessage(), $body, 'the exception message is in the error page');
        self::assertStringNotContainsString($error::class, $body, 'the exception class is in the error page');
        self::assertStringNotContainsString('Exception', $body);
        self::assertStringNotContainsString('#0 ', $body, 'a stack frame is in the error page');
        self::assertStringNotContainsString(__FILE__, $body);
        self::assertStringNotContainsString(self::projectRoot(), $body, 'the installation path is in the error page');
        self::assertStringNotContainsString('/vendor/', $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertStringNotContainsString('<pre>', $body, 'the detail block was rendered in production');
    }

    #[DataProvider('exceptions')]
    public function testProductionLeaksNothingThroughTheJsonShapeEither(\Throwable $error, int $expectedStatus): void
    {
        $response = $this->handler('production')->handle($error, true);
        $body = self::bodyOf($response);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('detail', $decoded, 'the detail was sent as JSON');
        self::assertArrayNotHasKey('trace', $decoded);
        self::assertSame($expectedStatus, $decoded['status'] ?? null);
        self::assertStringNotContainsString(self::SECRET_PATH, $body);
        self::assertStringNotContainsString(self::SECRET_VALUE, $body);
    }

    #[DataProvider('exceptions')]
    public function testDevelopmentShowsTheDetailThatProductionHides(\Throwable $error): void
    {
        // The other half of every assertion above: the detail exists, it is the
        // environment that decides whether the client sees it. Without this,
        // an error handler that returned an empty page would pass.
        $response = $this->handler('development')->handle($error);
        $body = self::bodyOf($response);

        self::assertStringContainsString(htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $body);
        self::assertStringContainsString('<pre>', $body);
    }

    public function testTheDetailIsEscapedInDevelopmentSoAMessageCannotBecomeMarkup(): void
    {
        $response = $this->handler('development')->handle(new \RuntimeException('<script>alert(1)</script>'));
        $body = self::bodyOf($response);

        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function testANotFoundAndAMethodNotAllowedAreBothPlain(): void
    {
        $handler = $this->handler('production');

        $notFound = $handler->notFound();
        self::assertSame(404, $notFound->getStatusCode());
        self::assertStringContainsString('Not found', self::bodyOf($notFound));
        self::assertStringNotContainsString('<pre>', self::bodyOf($notFound));
        self::assertStringNotContainsString(self::projectRoot(), self::bodyOf($notFound));

        $notAllowed = $handler->methodNotAllowed();
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertStringContainsString('Method not allowed', self::bodyOf($notAllowed));
        self::assertStringNotContainsString('<pre>', self::bodyOf($notAllowed));

        // And they say no more in development either: there is no detail to
        // have, because nothing was thrown.
        $development = $this->handler('development');
        self::assertStringNotContainsString('<pre>', self::bodyOf($development->notFound()));
        self::assertStringNotContainsString('<pre>', self::bodyOf($development->methodNotAllowed()));
    }

    public function testTheNotFoundJsonShapeCarriesNothingButTheStatus(): void
    {
        $body = self::bodyOf($this->handler('production')->notFound(true));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'Not found', 'status' => 404], $decoded);
    }

    public function testAServerErrorIsLoggedInEveryEnvironment(): void
    {
        foreach (['production', 'development', 'testing'] as $environment) {
            $this->logger = new RecordingLogger();
            $this->handler($environment)->handle(self::leakyException());

            self::assertNotSame([], $this->logger->records, 'nothing was logged in ' . $environment);
            self::assertSame('error', $this->logger->records[0]['level']);

            // The detail the client never sees has to reach the operator, or
            // the incident is invisible from both sides.
            $text = $this->logger->text();
            self::assertStringContainsString(self::SECRET_PATH, $text, 'the log has no detail in ' . $environment);
            self::assertStringContainsString('ConfigException', $text, 'the log does not say what was thrown');
            self::assertMatchesRegularExpression('/\.php:\d+/', $text, 'the log does not say where it happened');
        }
    }

    /**
     * OPEN GAP — a 4xx is not logged at all.
     *
     * `ErrorHandler::handle()` logs only when the status is 500 or above, so a
     * `SecurityException` — a refused address, a refused scheme, a refused
     * traversal, in other words every deliberate probe — produces a 400 and no
     * record anywhere. The fetch path logs its own refusals through
     * `FeedFetcher::failure()`, so this is a gap in visibility rather than in
     * defence, but a repeated probe of a public route leaves no trace.
     */
    public function testARefusedRequestIsRecordedForTheOperator(): void
    {
        $this->handler('production')->handle(new SecurityException('Refusing to fetch http://169.254.169.254/'));

        // One refusal is routine; a thousand is an attack. Neither is visible
        // if refusals are not written down, so they are logged below the level
        // a crash uses.
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('notice', $record['level']);
        self::assertStringContainsString('169.254.169.254', $record['message'] . json_encode($record['context']));
        self::assertSame(400, $record['context']['status'] ?? null);
    }

    public function testTheBootFailureBranchPrintsOneSentenceAndLogsTheRest(): void
    {
        // The one error path that cannot go through ErrorHandler, because it
        // fires before the kernel exists.
        $index = self::readFile(self::projectRoot() . '/public/index.php');

        self::assertStringContainsString('zFeeder cannot start', $index);
        self::assertStringContainsString("error_log('zfeeder boot failure: ' . \$e->getMessage())", $index);
        self::assertStringNotContainsString('echo $e->getMessage()', $index);
        self::assertStringNotContainsString('getTraceAsString', $index);
    }

    public function testNoErrorPathAnywhereInTheSourceTreePrintsATrace(): void
    {
        $offenders = [];
        foreach (self::sourceFiles(self::projectRoot() . '/src') as $file) {
            $source = self::readFile($file);
            foreach (['getTraceAsString', 'debug_print_backtrace', 'var_dump', 'print_r', 'phpinfo'] as $call) {
                if (str_contains($source, $call . '(')) {
                    $offenders[] = $file . ' calls ' . $call . '()';
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }
}
