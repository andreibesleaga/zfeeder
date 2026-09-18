<?php

declare(strict_types=1);

namespace Zfeeder\Cli;

use Symfony\Component\Console\Application as ConsoleApplication;
use Zfeeder\Cli\Command\AddFeedCommand;
use Zfeeder\Cli\Command\CheckConfigCommand;
use Zfeeder\Cli\Command\DocsConfigCommand;
use Zfeeder\Cli\Command\ExportCommand;
use Zfeeder\Cli\Command\HashPasswordCommand;
use Zfeeder\Cli\Command\ImportCommand;
use Zfeeder\Cli\Command\LegacyImportCommand;
use Zfeeder\Cli\Command\ListFeedsCommand;
use Zfeeder\Cli\Command\MigrateCommand;
use Zfeeder\Cli\Command\PurgeCommand;
use Zfeeder\Cli\Command\RefreshCommand;
use Zfeeder\Cli\Command\SeedCommand;
use Zfeeder\Kernel;
use Zfeeder\Version;

/** The `bin/zfeeder` console application. */
final class Application extends ConsoleApplication
{
    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct(Version::NAME, Version::NUMBER);

        $this->addCommands([
            new RefreshCommand($kernel),
            new ListFeedsCommand($kernel),
            new AddFeedCommand($kernel),
            new ImportCommand($kernel),
            new ExportCommand($kernel),
            new MigrateCommand($kernel),
            new CheckConfigCommand($kernel),
            new HashPasswordCommand(),
            new LegacyImportCommand($kernel),
            new PurgeCommand($kernel),
            new SeedCommand($kernel),
            new DocsConfigCommand(),
        ]);
    }

    public function kernel(): Kernel
    {
        return $this->kernel;
    }
}
