<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Zfeeder\Cli\Command\DocsConfigCommand;
use Zfeeder\Config\Schema;

#[CoversClass(DocsConfigCommand::class)]
final class DocsConfigCommandTest extends CliTestCase
{
    public function testCheckPassesAgainstTheCommittedFile(): void
    {
        $tester = $this->execute($this->kernel(), 'docs:config', ['--check' => true]);

        self::assertSame(
            Command::SUCCESS,
            $tester->getStatusCode(),
            'docs/CONFIGURATION.md is out of date. Run: bin/zfeeder docs:config',
        );
    }

    public function testGeneratesEveryOptionAndItsEnvironmentVariable(): void
    {
        $path = $this->tempPath('docs/CONFIGURATION.md');

        $tester = $this->execute($this->kernel(), 'docs:config', ['--output' => $path]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $document = (string) file_get_contents($path);

        foreach (Schema::all() as $key => $option) {
            self::assertStringContainsString('`' . $key . '`', $document);
            self::assertStringContainsString('`' . $option['env'] . '`', $document);
        }
    }

    public function testDocumentsThePrecedenceRuleAndTheWorkedExample(): void
    {
        $path = $this->tempPath('docs/CONFIGURATION.md');
        $this->execute($this->kernel(), 'docs:config', ['--output' => $path]);
        $document = (string) file_get_contents($path);

        self::assertStringContainsString('**Environment beats file beats default.**', $document);
        self::assertStringContainsString('ZF_DEFAULT_TEMPLATE=aqua', $document);
        self::assertStringContainsString('"default_template": "aqua"', $document);
        self::assertStringContainsString('| Environment variable | JSON key | Type | Default | Admin panel | Description |', $document);
    }

    public function testTheOutputIsDeterministicSoDriftDetectionWorks(): void
    {
        $first = $this->tempPath('docs/first.md');
        $second = $this->tempPath('docs/second.md');

        $this->execute($this->kernel(), 'docs:config', ['--output' => $first]);
        $this->execute($this->kernel(), 'docs:config', ['--output' => $second]);

        self::assertSame(file_get_contents($first), file_get_contents($second));
        self::assertSame(
            Command::SUCCESS,
            $this->execute($this->kernel(), 'docs:config', ['--output' => $first, '--check' => true])->getStatusCode(),
        );
    }

    public function testCheckFailsWhenTheFileHasDrifted(): void
    {
        $path = $this->tempPath('docs/CONFIGURATION.md');
        $this->execute($this->kernel(), 'docs:config', ['--output' => $path]);
        file_put_contents($path, "# Configuration\n\nSomebody edited this by hand.\n");

        $tester = $this->execute($this->kernel(), 'docs:config', ['--output' => $path, '--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is out of date', $this->flatten($tester->getDisplay()));
    }

    public function testCheckFailsWhenTheFileIsMissing(): void
    {
        $tester = $this->execute($this->kernel(), 'docs:config', [
            '--output' => $this->tempPath('docs/never-written.md'),
            '--check' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $this->flatten($tester->getDisplay()));
    }

    public function testSecretsAreNeverGivenADefaultValueInTheTable(): void
    {
        $path = $this->tempPath('docs/CONFIGURATION.md');
        $this->execute($this->kernel(), 'docs:config', ['--output' => $path]);
        $document = (string) file_get_contents($path);

        self::assertStringContainsString('## Secrets', $document);
        foreach (Schema::SECRET_KEYS as $key) {
            self::assertMatchesRegularExpression(
                '/\| `' . preg_quote($key, '/') . '` \| [^|]*\| \*\(empty/',
                $document,
            );
        }
    }
}
