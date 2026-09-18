<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zfeeder\Cli\Application;
use Zfeeder\Cli\Command\HashPasswordCommand;

#[CoversClass(HashPasswordCommand::class)]
final class HashPasswordCommandTest extends CliTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testTheHashVerifiesAgainstThePassword(): void
    {
        $tester = $this->execute($this->kernel(), 'hash-password', ['password' => self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $hash = $this->firstLine($tester->getDisplay());
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertFalse(password_verify('something else entirely', $hash));
    }

    public function testStandardOutputCarriesOnlyTheHashAndTheTwoConfigurationLines(): void
    {
        $tester = $this->runCapturingStreams(['password' => self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $lines = array_values(array_filter(explode("\n", trim($tester->getDisplay())), static fn (string $l): bool => $l !== ''));
        self::assertCount(3, $lines, 'stdout must stay pipeable: ' . $tester->getDisplay());

        $hash = $lines[0];
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertSame('ZF_ADMIN_PASSWORD_HASH=' . $hash, $lines[1]);
        self::assertSame(sprintf('"admin_password_hash": "%s"', $hash), $lines[2]);

        // The warning about ps and shell history belongs on stderr, not stdout.
        self::assertStringContainsString('shell history', $this->flatten($tester->getErrorOutput()));
    }

    public function testPromptsTwiceAndAcceptsMatchingAnswers(): void
    {
        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('hash-password'));
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue(password_verify(self::PASSWORD, $this->firstLine($tester->getDisplay())));
    }

    public function testMismatchedAnswersAreAUsageError(): void
    {
        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('hash-password'));
        $tester->setInputs([self::PASSWORD, 'a different password']);
        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('did not match', $this->flatten($tester->getDisplay()));
        self::assertStringNotContainsString('$argon2id$', $tester->getDisplay());
    }

    public function testAShortPasswordIsRefused(): void
    {
        $tester = $this->execute($this->kernel(), 'hash-password', ['password' => 'short']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('at least 12 characters', $this->flatten($tester->getDisplay()));
        self::assertStringNotContainsString('$argon2id$', $tester->getDisplay());
    }

    public function testExactlyTheMinimumLengthIsAccepted(): void
    {
        $password = str_repeat('a', HashPasswordCommand::MINIMUM_LENGTH);

        $tester = $this->execute($this->kernel(), 'hash-password', ['password' => $password]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue(password_verify($password, $this->firstLine($tester->getDisplay())));
    }

    /** @param array<string, mixed> $input */
    private function runCapturingStreams(array $input): CommandTester
    {
        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('hash-password'));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function firstLine(string $display): string
    {
        foreach (explode("\n", $display) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '$argon2id$')) {
                return $line;
            }
        }

        self::fail('No hash in the output: ' . $display);
    }
}
