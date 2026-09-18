<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turn a password into the hash the panel stores.
 *
 * zFeeder 1.6 kept `md5($password)` in `config.php`, unsalted, which by 2026 is
 * a plaintext password with extra steps. 2.0 stores an Argon2id hash and never
 * stores the password at all.
 *
 * Two deliberate details:
 *
 *  - the prompts, the confirmation and every error go to standard *error*, and
 *    only the hash and the two configuration lines go to standard output. That
 *    is what makes `bin/zfeeder hash-password | head -1` work, and what lets an
 *    installer capture the hash without capturing the chatter;
 *  - passing the password as an argument is supported for scripted installs,
 *    with the warning it deserves: an argument is visible in `ps` and lands in
 *    the shell history.
 */
#[AsCommand(
    name: 'hash-password',
    description: 'Create the Argon2id hash for the administration password.',
)]
final class HashPasswordCommand extends Command
{
    use CommandInput;

    /** The exit code for a configuration problem: `EX_CONFIG` from sysexits.h. */
    private const int EXIT_CONFIG = 78;

    /**
     * Long enough that an offline attack on the hash is not the weak link.
     * Complexity rules are not imposed: length is what matters, and rules push
     * people towards passwords they have to write down.
     */
    public const int MINIMUM_LENGTH = 12;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('password', InputArgument::OPTIONAL, 'The password. Omit it to be prompted twice, without echo.')
            ->setHelp(<<<'HELP'
                Creates the Argon2id hash that goes into ZF_ADMIN_PASSWORD_HASH or into
                config.json. The password itself is never stored anywhere.

                Worked example:

                  <info>bin/zfeeder hash-password</info>

                  <comment>Password: (not echoed)</comment>
                  <comment>Repeat:   (not echoed)</comment>
                  <comment>$argon2id$v=19$m=65536,t=4,p=1$...</comment>
                  <comment>ZF_ADMIN_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$...</comment>
                  <comment>"admin_password_hash": "$argon2id$v=19$m=65536,t=4,p=1$..."</comment>

                Only those three lines go to standard output, so the hash can be piped:

                  <info>bin/zfeeder hash-password | head -1 > /run/secrets/zfeeder-admin</info>

                In an unattended install, read the password from a file rather than
                writing it on the command line, where <comment>ps</comment> and the shell history can see it:

                  <info>bin/zfeeder hash-password "$(cat /run/secrets/password)"</info>

                On a terminal with no stty the prompt cannot hide what you type; it then
                asks visibly rather than aborting.

                The minimum length is 12 characters. Exit codes: 0 on success, 2 when the
                password is too short or the two prompts did not match, 78 when this PHP
                build has no Argon2id support.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Everything conversational goes to stderr so stdout stays pipeable.
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $io = new SymfonyStyle($input, $errors);

        if (!\defined('PASSWORD_ARGON2ID')) {
            $io->error('This PHP build has no Argon2id support. Rebuild PHP with libargon2 or libsodium.');

            return self::EXIT_CONFIG;
        }

        $password = $this->argumentString($input, 'password');
        if ($password === '') {
            $password = $this->prompt($io, $input, $errors);
            if ($password === null) {
                return self::INVALID;
            }
        } else {
            $io->warning('A password given as an argument is visible in ps and in your shell history.');
        }

        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            $io->error(sprintf('The password must be at least %d characters long.', self::MINIMUM_LENGTH));

            return self::INVALID;
        }

        $hash = password_hash($password, \PASSWORD_ARGON2ID);

        $output->writeln($hash, OutputInterface::OUTPUT_RAW);
        $output->writeln('ZF_ADMIN_PASSWORD_HASH=' . $hash, OutputInterface::OUTPUT_RAW);
        $output->writeln(sprintf('"admin_password_hash": "%s"', $hash), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** @return string|null null when the two answers did not match */
    private function prompt(SymfonyStyle $io, InputInterface $input, OutputInterface $errors): ?string
    {
        $helpers = $this->getHelperSet();
        $helper = $helpers !== null && $helpers->has('question') ? $helpers->get('question') : null;
        if (!$helper instanceof QuestionHelper) {
            $io->error('No question helper is available; pass the password as an argument instead.');

            return null;
        }

        // The hidden fallback is left on: on a terminal without stty (some
        // containers, some CI runners) an echoed prompt is better than an
        // abort, and the help says so.
        $first = new Question('Password: ');
        $first->setHidden(true);
        $second = new Question('Repeat:   ');
        $second->setHidden(true);

        /** @var mixed $one */
        $one = $helper->ask($input, $errors, $first);
        /** @var mixed $two */
        $two = $helper->ask($input, $errors, $second);

        $one = \is_string($one) ? $one : '';
        $two = \is_string($two) ? $two : '';

        // hash_equals, not ===: the comparison is on a secret, and constant time
        // costs nothing here.
        if (!hash_equals($one, $two)) {
            $io->error('The two passwords did not match.');

            return null;
        }

        if ($one === '') {
            $io->error('An empty password is not acceptable.');

            return null;
        }

        return $one;
    }
}
