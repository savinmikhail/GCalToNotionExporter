#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Command\Calendar\SyncGCalToNotionCommand;
use App\Command\GetRefreshTokenCommand;
use App\Command\RenumberQuestionsCommand;
use Dotenv\Dotenv;
use Symfony\Component\Console\Application;

Dotenv::createImmutable(__DIR__)->safeLoad();

$application = new Application('GCalToNotionExporter');
$application->addCommands([
    new SyncGCalToNotionCommand(),
    new RenumberQuestionsCommand(),
    new GetRefreshTokenCommand(),
]);
$application->run();
