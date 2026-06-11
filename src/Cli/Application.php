<?php

declare(strict_types=1);

namespace EduDeps\Cli;

use EduDeps\Cli\Command\DoctorCommand;
use EduDeps\Cli\Command\FixShortTagsCommand;
use EduDeps\Cli\Command\RectorCommand;
use EduDeps\Cli\Command\ScanCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application
{
    private SymfonyApplication $app;

    public function __construct()
    {
        $this->app = new SymfonyApplication('edu-deps', '0.6.0');
        $this->app->add(new ScanCommand());
        $this->app->add(new DoctorCommand());
        $this->app->add(new RectorCommand());
        $this->app->add(new FixShortTagsCommand());
    }

    public function run(): int
    {
        return $this->app->run();
    }
}
