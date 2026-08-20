<?php

declare(strict_types=1);

namespace App\Application;

use App\Command\AbstractPleadCommand;
use App\Command\Audit\AuditExportCommand;
use App\Command\Audit\AuditTrailCommand;
use App\Command\Config\ConfigEditCommand;
use App\Command\Config\ConfigGetCommand;
use App\Command\Config\ConfigListCommand;
use App\Command\Config\ConfigPathCommand;
use App\Command\Config\ConfigSetCommand;
use App\Command\Config\ConfigViewCommand;
use App\Command\Db\DbPathCommand;
use App\Command\Db\DbQueryCommand;
use App\Command\Domain\DomainAddCommand;
use App\Command\Domain\DomainDescriptorCommand;
use App\Command\Domain\DomainGetCommand;
use App\Command\Domain\DomainListCommand;
use App\Command\Domain\DomainRemoveCommand;
use App\Command\Domain\DomainSetCommand;
use App\Command\Domain\DomainTrafficGetCommand;
use App\Command\Domain\DomainTrafficSetCommand;
use App\Command\Mail\Address\AddressExportCommand;
use App\Command\Mail\Address\AddressGetCommand;
use App\Command\Mail\Address\AddressListCommand;
use App\Command\Mail\Address\AddressPasswordCommand;
use App\Command\Mail\Address\AddressRemoveCommand;
use App\Command\Mail\Address\AddressRenameCommand;
use App\Command\Mail\Address\AddressSetCommand;
use App\Command\Mail\Alias\AliasAddCommand;
use App\Command\Mail\Alias\AliasGetCommand;
use App\Command\Mail\Alias\AliasListCommand;
use App\Command\Mail\Alias\AliasRemoveCommand;
use App\Command\Mail\Alias\AliasSetCommand;
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
use App\Command\Server\Components\ComponentsInstallCommand;
use App\Command\Server\Components\ComponentsListCommand;
use App\Command\Server\Extension\ExtensionCallCommand;
use App\Command\Server\Extension\ExtensionGetCommand;
use App\Command\Server\Extension\ExtensionInstallCommand;
use App\Command\Server\Extension\ExtensionListCommand;
use App\Command\Server\Extension\ExtensionUninstallCommand;
use App\Command\Server\Ip\IpAddCommand;
use App\Command\Server\Ip\IpGetCommand;
use App\Command\Server\Ip\IpListCommand;
use App\Command\Server\Ip\IpRemoveCommand;
use App\Command\Server\Ip\IpSetCommand;
use App\Command\Server\ServerAdminCommand;
use App\Command\Server\ServerExecCommand;
use App\Command\Server\ServerInfoCommand;
use App\Command\Server\ServerRefCommand;
use App\Command\Server\Service\ServiceRestartCommand;
use App\Command\Server\Service\ServiceStartCommand;
use App\Command\Server\Service\ServiceStatusCommand;
use App\Command\Server\Service\ServiceStopCommand;
use App\Command\Server\Session\SessionGetCommand;
use App\Command\Server\Session\SessionListCommand;
use App\Command\Server\Session\SessionTerminateCommand;
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
            new AuditExportCommand(),
            new AuditTrailCommand(),
            new ConfigEditCommand(),
            new ConfigGetCommand(),
            new ConfigListCommand(),
            new ConfigPathCommand(),
            new ConfigSetCommand(),
            new ConfigViewCommand(),
            new DbPathCommand(),
            new DbQueryCommand(),
            new DomainAddCommand(),
            new DomainDescriptorCommand(),
            new DomainGetCommand(),
            new DomainListCommand(),
            new DomainRemoveCommand(),
            new DomainSetCommand(),
            new DomainTrafficGetCommand(),
            new DomainTrafficSetCommand(),
            new GroupAddCommand(),
            new GroupGetCommand(),
            new GroupListCommand(),
            new GroupRemoveCommand(),
            new GroupSetCommand(),
            new GroupWatchCommand(),
            new AddressRemoveCommand(),
            new AddressExportCommand(),
            new AddressGetCommand(),
            new AddressListCommand(),
            new AddressPasswordCommand(),
            new AddressRenameCommand(),
            new AddressSetCommand(),
            new AliasAddCommand(),
            new AliasGetCommand(),
            new AliasListCommand(),
            new AliasRemoveCommand(),
            new AliasSetCommand(),
            new AutoresponderGetCommand(),
            new AutoresponderListCommand(),
            new AutoresponderSetCommand(),
            new AutoresponderWatchCommand(),
            new ServerInfoCommand(),
            new ServerAdminCommand(),
            new ServerExecCommand(),
            new ServerRefCommand(),
            new ComponentsInstallCommand(),
            new ComponentsListCommand(),
            new ExtensionCallCommand(),
            new ExtensionGetCommand(),
            new ExtensionInstallCommand(),
            new ExtensionListCommand(),
            new ExtensionUninstallCommand(),
            new IpAddCommand(),
            new IpGetCommand(),
            new IpListCommand(),
            new IpRemoveCommand(),
            new IpSetCommand(),
            new SessionGetCommand(),
            new SessionListCommand(),
            new SessionTerminateCommand(),
            new ServiceStartCommand(),
            new ServiceStopCommand(),
            new ServiceRestartCommand(),
            new ServiceStatusCommand(),
        ]);
    }

    public function context(): RuntimeContext
    {
        if (null === $this->context) {
            throw new \LogicException('RuntimeContext has not been built yet.');
        }

        return $this->context;
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
}
