<?php

declare(strict_types=1);

namespace App\Application;

use App\Command\AbstractPleadCommand;
use App\Command\AutoReply\AutoReplyGetCommand;
use App\Command\AutoReply\AutoReplyListCommand;
use App\Command\AutoReply\AutoReplySetCommand;
use App\Command\AutoReply\AutoReplyWatchCommand;
use App\Command\Config\ConfigGetCommand;
use App\Command\Config\ConfigEditCommand;
use App\Command\Config\ConfigListCommand;
use App\Command\Config\ConfigPathCommand;
use App\Command\Config\ConfigSetCommand;
use App\Command\Config\ConfigViewCommand;
use App\Command\Mail\MailAddCommand;
use App\Command\Mail\MailGetCommand;
use App\Command\Mail\MailListCommand;
use App\Command\Mail\MailRemoveCommand;
use App\Command\Mail\MailSetCommand;
use App\Command\Mail\MailWatchCommand;
use App\Config\PathProvider\PathProviderFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PleadApplication extends Application
{
    public const NAME = 'plead';
    public const VERSION = '0.1.0';

    private ?RuntimeContext $context = null;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->setAutoExit(false);
        $this->addCommands([
            new AutoReplyGetCommand(),
            new AutoReplyListCommand(),
            new AutoReplySetCommand(),
            new AutoReplyWatchCommand(),
            new ConfigGetCommand(),
            new ConfigSetCommand(),
            new ConfigListCommand(),
            new ConfigPathCommand(),
            new ConfigViewCommand(),
            new ConfigEditCommand(),
            new MailGetCommand(),
            new MailListCommand(),
            new MailSetCommand(),
            new MailAddCommand(),
            new MailRemoveCommand(),
            new MailWatchCommand(),
        ]);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'Explicit config file path; skips discovery and loads only this file.',
        ));
        $definition->addOption(new InputOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Log mutations without performing them on the server.',
        ));
        $definition->addOption(new InputOption(
            'log-level',
            null,
            InputOption::VALUE_REQUIRED,
            'Baseline file log level (default: info). -v/-vv/-vvv also raise the file level.',
        ));

        return $definition;
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->context) {
            $command->mergeApplicationDefinition();
            $input->bind($command->getDefinition());
            $this->context = new RuntimeContext(
                PathProviderFactory::create(),
                $input->getOption('config') ?: null,
                (bool) $input->getOption('dry-run'),
                $input->getOption('log-level') ?: null,
                $output->getVerbosity(),
            );
        }

        if ($command instanceof AbstractPleadCommand) {
            $command->setContext($this->context);
        }

        return parent::doRunCommand($command, $input, $output);
    }

    public function context(): RuntimeContext
    {
        if (null === $this->context) {
            throw new \LogicException('RuntimeContext has not been built yet.');
        }

        return $this->context;
    }
}
