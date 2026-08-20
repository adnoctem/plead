<?php

declare(strict_types=1);

namespace App\Application;

use App\Command\AbstractPleadCommand;
use App\Command\Config\ConfigEditCommand;
use App\Command\Config\ConfigGetCommand;
use App\Command\Config\ConfigListCommand;
use App\Command\Config\ConfigPathCommand;
use App\Command\Config\ConfigSetCommand;
use App\Command\Config\ConfigViewCommand;
use App\Command\Db\DbPathCommand;
use App\Command\Db\DbQueryCommand;
use App\Command\Domain\DomainGetCommand;
use App\Command\Domain\DomainListCommand;
use App\Command\Domain\DomainSetCommand;
use App\Command\Mail\Address\AddressDeleteCommand;
use App\Command\Mail\Address\AddressExportCommand;
use App\Command\Mail\Address\AddressGetCommand;
use App\Command\Mail\Address\AddressListCommand;
use App\Command\Mail\Address\AddressPasswordCommand;
use App\Command\Mail\Address\AddressSetCommand;
use App\Command\Mail\Autoresponder\AutoresponderGetCommand;
use App\Command\Mail\Autoresponder\AutoresponderListCommand;
use App\Command\Mail\Autoresponder\AutoresponderSetCommand;
use App\Command\Mail\Autoresponder\AutoresponderWatchCommand;
use App\Command\Mail\Group\GroupAddCommand;
use App\Command\Mail\Group\GroupGetCommand;
use App\Command\Mail\Group\GroupListCommand;
use App\Command\Mail\Group\GroupRemoveCommand;
use App\Command\Mail\Group\GroupSetCommand;
use App\Command\Mail\Group\GroupWatchCommand;
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
            new ConfigEditCommand(),
            new ConfigGetCommand(),
            new ConfigListCommand(),
            new ConfigPathCommand(),
            new ConfigSetCommand(),
            new ConfigViewCommand(),
            new DbPathCommand(),
            new DbQueryCommand(),
            new DomainGetCommand(),
            new DomainListCommand(),
            new DomainSetCommand(),
            new GroupAddCommand(),
            new GroupGetCommand(),
            new GroupListCommand(),
            new GroupRemoveCommand(),
            new GroupSetCommand(),
            new GroupWatchCommand(),
            new AddressDeleteCommand(),
            new AddressExportCommand(),
            new AddressGetCommand(),
            new AddressListCommand(),
            new AddressPasswordCommand(),
            new AddressSetCommand(),
            new AutoresponderGetCommand(),
            new AutoresponderListCommand(),
            new AutoresponderSetCommand(),
            new AutoresponderWatchCommand(),
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
