<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\CheckConfigCommand;

#[CoversClass(CheckConfigCommand::class)]
final class CheckConfigCommandTest extends CliTestCase
{
    /** Distinctive enough that its appearance anywhere in the output is proof of a leak. */
    private const string SECRET = '$argon2id$v=19$LEAKED-PASSWORD-HASH-CANARY';
    private const string REFRESH_SECRET = 'LEAKED-REFRESH-KEY-CANARY';

    public function testAWorkingInstallationPassesEveryCheck(): void
    {
        $kernel = $this->kernel(['admin_password_hash' => self::SECRET, 'base_url' => 'https://example.com/']);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'check-config');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('All 9 checks passed', $this->flatten($tester->getDisplay()));
    }

    public function testTheSecretsAreNeverPrintedOnlyWhetherTheyAreSet(): void
    {
        $kernel = $this->kernel([
            'admin_password_hash' => self::SECRET,
            'refresh_key' => self::REFRESH_SECRET,
        ]);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $display = $this->execute($kernel, 'check-config')->getDisplay();

        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertStringNotContainsString('LEAKED', $display);
        self::assertMatchesRegularExpression('/admin_password_hash\s+ZF_ADMIN_PASSWORD_HASH\s+set\b/', $display);
        self::assertMatchesRegularExpression('/refresh_key\s+ZF_REFRESH_KEY\s+set\b/', $display);
    }

    public function testAnUnsetSecretIsReportedAsNotSet(): void
    {
        $kernel = $this->kernel();
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $display = $this->flatten($this->execute($kernel, 'check-config')->getDisplay());

        self::assertStringContainsString('admin_password_hash ZF_ADMIN_PASSWORD_HASH not set', $display);
    }

    public function testABrokenConfigurationFailsAndSaysWhy(): void
    {
        // Nothing was ever written, so the data directory does not exist.
        $tester = $this->execute($this->kernel(), 'check-config');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $this->flatten($tester->getDisplay());
        self::assertStringContainsString('[FAIL] Data directory exists and is writable', $display);
        self::assertStringContainsString('[FAIL] At least one readable category', $display);
        self::assertStringContainsString('checks failed', $display);
    }

    public function testAnEnabledPanelWithNoPasswordFails(): void
    {
        $kernel = $this->kernel(['admin_enabled' => true]);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'check-config');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            '[FAIL] Administration password is set — the panel is enabled but has no password',
            $this->flatten($tester->getDisplay()),
        );
    }

    public function testABaseUrlWithoutATrailingSlashFails(): void
    {
        $kernel = $this->kernel(['admin_enabled' => false, 'base_url' => 'https://example.com/feeds']);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'check-config');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] base_url ends with a slash', $this->flatten($tester->getDisplay()));
    }

    public function testAMissingDefaultTemplateFails(): void
    {
        $kernel = $this->kernel(['admin_enabled' => false, 'default_template' => 'no-such-template']);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'check-config');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] Default template exists', $this->flatten($tester->getDisplay()));
    }

    public function testJsonReportIsRedactedToo(): void
    {
        $kernel = $this->kernel(['admin_password_hash' => self::SECRET, 'admin_enabled' => false]);
        $this->seed($kernel, 'news', $this->feed('https://one.example/feed.xml'));

        $tester = $this->execute($kernel, 'check-config', ['--json' => true]);
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('LEAKED', $display);

        $report = $this->decode($display);
        self::assertTrue($report['ok']);
        self::assertIsArray($report['options']);

        $secrets = array_values(array_filter(
            $report['options'],
            static fn (mixed $option): bool => \is_array($option) && $option['secret'] === true,
        ));
        self::assertCount(2, $secrets);
        foreach ($secrets as $option) {
            self::assertIsArray($option);
            self::assertContains($option['value'], ['set', 'not set']);
        }
    }
}
