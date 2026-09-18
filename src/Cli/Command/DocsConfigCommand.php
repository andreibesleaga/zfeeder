<?php

declare(strict_types=1);

namespace Zfeeder\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zfeeder\Config\Schema;

/**
 * Generate `docs/CONFIGURATION.md` from {@see Schema}.
 *
 * Hand-written option documentation rots: an option is added, the table is not,
 * and six months later the documented default is a lie. Here the table *is* the
 * schema, printed. `--check` makes CI fail when the file on disk no longer
 * matches what the schema would produce, which is the only way the two stay
 * together.
 *
 * The output contains no timestamp and no version number on purpose: a
 * generator whose output changes on every run cannot be checked for drift.
 */
#[AsCommand(
    name: 'docs:config',
    description: 'Generate docs/CONFIGURATION.md from the configuration schema.',
)]
final class DocsConfigCommand extends Command
{
    use CommandInput;

    /** Relative to the project root, and referenced by name in CONTRIBUTING.md. */
    private const string RELATIVE_PATH = 'docs/CONFIGURATION.md';

    /** Group key => the heading and the sentence under it. */
    private const array GROUPS = [
        'general' => ['General', 'Where zFeeder keeps its data, which storage backend it uses, and how it logs.'],
        'feeds' => ['Feeds', 'Fetching: what is fetched, how often, how large, and from where.'],
        'display' => ['Display', 'What a rendered page looks like. These are the 1.6 display settings, plus what modern feeds made necessary.'],
        'admin' => ['Administration panel', 'The panel, its session and its rate limiting.'],
        'embedding' => ['Embedding and the API', 'Serving feeds to other pages: the JSON API, OPML export and the CORS and frame rules.'],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Do not write; exit 1 if the file on disk differs from what would be generated.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write somewhere other than docs/CONFIGURATION.md.')
            ->setHelp(<<<'HELP'
                Writes the configuration reference from the one place options are defined,
                <comment>src/Config/Schema.php</comment>, so the documentation cannot drift away from the
                program.

                Worked example, after adding an option to the schema:

                  <info>bin/zfeeder docs:config</info>

                  <comment>Wrote docs/CONFIGURATION.md (44 options in 5 groups).</comment>

                and in CI, where the point is to fail rather than to fix:

                  <info>bin/zfeeder docs:config --check</info>

                  <comment>docs/CONFIGURATION.md is out of date. Run bin/zfeeder docs:config and commit the result.</comment>

                The generated file carries no timestamp, so running it twice produces the
                same bytes and <info>--check</info> is meaningful.

                Exit codes: 0 when the file was written or is up to date, 1 when <info>--check</info>
                found a difference or the file could not be written.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->option($input, 'output') ?? \dirname(__DIR__, 3) . '/' . self::RELATIVE_PATH;
        $document = $this->generate();

        if ($this->flag($input, 'check')) {
            $current = is_file($path) ? file_get_contents($path) : false;
            if ($current === false) {
                $io->error(sprintf('%s does not exist. Run bin/zfeeder docs:config and commit the result.', $path));

                return self::FAILURE;
            }
            if ($current !== $document) {
                $io->error(sprintf('%s is out of date. Run bin/zfeeder docs:config and commit the result.', $path));

                return self::FAILURE;
            }
            $io->success($path . ' is up to date.');

            return self::SUCCESS;
        }

        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $io->error('Cannot create the documentation directory: ' . $directory);

            return self::FAILURE;
        }
        if (@file_put_contents($path, $document) === false) {
            $io->error('Cannot write ' . $path);

            return self::FAILURE;
        }

        $io->success(sprintf(
            'Wrote %s (%d options in %d groups).',
            $path,
            \count(Schema::all()),
            \count(Schema::groups()),
        ));

        return self::SUCCESS;
    }

    /** The whole document. Deterministic: same schema in, same bytes out. */
    private function generate(): string
    {
        $out = "# Configuration\n\n";
        $out .= "<!-- Generated by `bin/zfeeder docs:config` from src/Config/Schema.php. Do not edit by hand. -->\n\n";
        $out .= "Every zFeeder option is defined once, in `src/Config/Schema.php`. That table is\n"
            . "what the admin panel builds its form from, what `bin/zfeeder check-config` validates\n"
            . "against, and what this page is generated from, so the three cannot drift apart.\n"
            . "Each option has an environment variable, a JSON key for `data/config.json`, a type\n"
            . "and a default. Nothing else is configurable: there is no `config.php` to edit and no\n"
            . "code to patch.\n\n";

        $out .= $this->precedenceSection();

        foreach (Schema::groups() as $group) {
            $out .= $this->groupSection($group);
        }

        $out .= $this->secretsSection();

        return $out;
    }

    private function precedenceSection(): string
    {
        $out = "## How a value is decided\n\n";
        $out .= "**Environment beats file beats default.** For every option zFeeder takes the\n"
            . "default from the schema, overlays the value in `data/config.json` if there is one,\n"
            . "and overlays the environment variable if it is set. The first value found, reading\n"
            . "from the bottom of this list upwards, is the one that applies:\n\n";
        $out .= "1. **environment** — `ZF_*`, set by the container, the systemd unit or the shell;\n";
        $out .= "2. **file** — `data/config.json`, which is what the admin panel writes;\n";
        $out .= "3. **default** — the value in the schema.\n\n";
        $out .= "An option set in the environment is shown read-only in the admin panel and cannot\n"
            . "be changed from there: a deployment that pins a setting must not be quietly\n"
            . "overridden by whoever is logged in. `bin/zfeeder check-config` prints the source of\n"
            . "every value, which is usually the fastest way to answer \"why is it still doing that?\".\n\n";
        $out .= "An environment variable set to the empty string counts as *not set* for numbers,\n"
            . "booleans and enumerations, because platforms routinely export empty placeholders.\n"
            . "For string options an empty value is kept, which is how `ZF_URL=` asks for the URL\n"
            . "to be detected from the request again after the config file pinned one.\n\n";

        $out .= "### The same option, all three ways\n\n";
        $out .= "Setting the default template to `aqua`, three ways, each beating the one\nbefore it:\n\n";
        $out .= "1. The default, in `src/Config/Schema.php` — what you get if you do nothing:\n\n";
        $out .= "```php\n";
        $out .= "'default_template' => ['env' => 'ZF_DEFAULT_TEMPLATE', 'default' => 'bluelogos', ...]\n";
        $out .= "```\n\n";
        $out .= "2. `data/config.json` — what the admin panel writes when you pick a template:\n\n";
        $out .= "```json\n";
        $out .= "{\n    \"default_template\": \"aqua\"\n}\n";
        $out .= "```\n\n";
        $out .= "3. The environment — wins over both, and locks the field in the panel:\n\n";
        $out .= "```sh\n";
        $out .= "export ZF_DEFAULT_TEMPLATE=aqua\n";
        $out .= "```\n\n";
        $out .= "```console\n";
        $out .= "$ bin/zfeeder check-config\n";
        $out .= "Option            Environment variable    Value   Source\n";
        $out .= "default_template  ZF_DEFAULT_TEMPLATE     aqua    env\n";
        $out .= "```\n\n";

        return $out;
    }

    private function groupSection(string $group): string
    {
        [$heading, $blurb] = self::GROUPS[$group] ?? [ucfirst($group), ''];

        $out = '## ' . $heading . "\n\n";
        if ($blurb !== '') {
            $out .= $blurb . "\n\n";
        }

        $out .= "| Environment variable | JSON key | Type | Default | Admin panel | Description |\n";
        $out .= "| --- | --- | --- | --- | --- | --- |\n";

        foreach (Schema::all() as $key => $option) {
            if ($option['group'] !== $group) {
                continue;
            }
            $out .= sprintf(
                "| `%s` | `%s` | %s | %s | %s | %s |\n",
                $option['env'],
                $key,
                $this->describeType($option),
                $this->describeDefault($key, $option),
                $option['adminEditable'] ? 'yes' : 'no',
                $this->escapeCell($option['label'] . '. ' . $option['help']),
            );
        }

        return $out . "\n";
    }

    /**
     * @param array{type: string, values?: list<string>, min?: int, max?: int} $option
     */
    private function describeType(array $option): string
    {
        return match ($option['type']) {
            Schema::TYPE_ENUM => 'enum: ' . implode(', ', array_map(static fn (string $v): string => '`' . $v . '`', $option['values'] ?? [])),
            Schema::TYPE_INT => isset($option['min'], $option['max'])
                ? sprintf('int, %d–%d', $option['min'], $option['max'])
                : 'int',
            Schema::TYPE_BOOL => 'bool',
            default => 'string',
        };
    }

    /**
     * @param array{default: string|int|bool} $option
     */
    private function describeDefault(string $key, array $option): string
    {
        if (\in_array($key, Schema::SECRET_KEYS, true)) {
            // Never print a secret's default, even though today every one of
            // them is the empty string: the table must stay safe to publish.
            return '*(empty — see [Secrets](#secrets))*';
        }

        $default = $option['default'];
        if (\is_bool($default)) {
            return $default ? '`true`' : '`false`';
        }
        if (\is_int($default)) {
            return '`' . $default . '`';
        }

        return $default === '' ? '*(empty)*' : '`' . $default . '`';
    }

    private function secretsSection(): string
    {
        $keys = implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', Schema::SECRET_KEYS));

        $out = "## Secrets\n\n";
        $out .= 'These options hold credentials: ' . $keys . ".\n\n";
        $out .= "They are never printed by `bin/zfeeder check-config`, which reports them as `set`\n"
            . "or `not set`, and the admin panel will not display or edit them. Create the password\n"
            . "hash with `bin/zfeeder hash-password`, and prefer the environment over `config.json`\n"
            . "wherever the platform offers a secret store.\n";

        return $out;
    }

    /** A cell must not break the table, and a newline inside one would. */
    private function escapeCell(string $text): string
    {
        return trim(str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $text));
    }
}
