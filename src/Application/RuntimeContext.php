<?php

declare(strict_types=1);

namespace App\Application;

use App\Config\ConfigLoader;
use App\Config\PathProvider\PathProviderInterface;
use App\Database\Connection;
use App\Gateway\PleskEndpoint;
use App\Gateway\PleskMailGateway;
use App\Logging\ContextInterpolatingFormatter;
use App\Reconciler\AutoReplyReconciler;
use App\Reconciler\MailGroupReconciler;
use App\Repository\AutoReplyRepository;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use App\Template\AutoReplyRenderer;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PleskX\Api\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RuntimeContext
{
    private ?array $config = null;
    private ?ConfigLoader $configLoader = null;
    private ?Connection $connection = null;
    private ?AutoReplyRepository $autoReplyRepository = null;
    private ?MailGroupRepository $mailGroupRepository = null;
    private ?SyncLogRepository $syncLogRepository = null;
    private ?PleskMailGateway $gateway = null;
    private ?AutoReplyReconciler $reconciler = null;
    private ?MailGroupReconciler $reconcilerMail = null;
    private ?AutoReplyRenderer $renderer = null;
    private ?LoggerInterface $logger = null;

    public function __construct(
        public readonly PathProviderInterface $paths,
        private readonly ?string $explicitConfigPath,
        private readonly bool $dryRun,
        private readonly ?string $logLevelOption,
        private readonly int $verbosity,
        ?PleskMailGateway $gateway = null,
        ?AutoReplyRepository $autoReplyRepository = null,
        ?MailGroupRepository $mailGroupRepository = null,
        ?SyncLogRepository $syncLogRepository = null,
        ?Connection $connection = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->gateway = $gateway;
        $this->autoReplyRepository = $autoReplyRepository;
        $this->mailGroupRepository = $mailGroupRepository;
        $this->syncLogRepository = $syncLogRepository;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public function configLoader(): ConfigLoader
    {
        return $this->configLoader ??= new ConfigLoader($this->paths);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config ??= $this->configLoader()->load($this->explicitConfigPath);
    }

    public function connection(): Connection
    {
        return $this->connection ??= new Connection($this->paths->dataDir() . '/plead.sqlite');
    }

    public function autoReplyRepository(): AutoReplyRepository
    {
        return $this->autoReplyRepository ??= new AutoReplyRepository($this->connection());
    }

    public function mailGroupRepository(): MailGroupRepository
    {
        return $this->mailGroupRepository ??= new MailGroupRepository($this->connection());
    }

    public function syncLogRepository(): SyncLogRepository
    {
        return $this->syncLogRepository ??= new SyncLogRepository($this->connection());
    }

    public function gateway(): PleskMailGateway
    {
        return $this->gateway ??= new PleskMailGateway(
            $this->pleskClient(),
            $this->dryRun,
            $this->logger(),
        );
    }

    private function pleskClient(): Client
    {
        $config = $this->config();
        $endpoint = PleskEndpoint::fromConfig((string) $config['plesk']['host']);
        $client = new Client($endpoint->host, $endpoint->port, $endpoint->protocol);

        if (!empty($config['plesk']['secret_key'])) {
            $client->setSecretKey($config['plesk']['secret_key']);
        } elseif (!empty($config['plesk']['login']) && !empty($config['plesk']['password'])) {
            $client->setCredentials($config['plesk']['login'], $config['plesk']['password']);
        } else {
            throw new \RuntimeException(
                'No Plesk authentication configured. Set plesk.secret_key or plesk.login with plesk.password.',
            );
        }

        return $client;
    }

    public function reconciler(): AutoReplyReconciler
    {
        return $this->reconciler ??= new AutoReplyReconciler(
            $this->autoReplyRepository(),
            $this->syncLogRepository(),
            $this->gateway(),
            $this->logger(),
            $this->dryRun,
        );
    }

    public function reconcilerMail(): MailGroupReconciler
    {
        return $this->reconcilerMail ??= new MailGroupReconciler(
            $this->mailGroupRepository(),
            $this->syncLogRepository(),
            $this->gateway(),
            $this->logger(),
            $this->dryRun,
        );
    }

    public function renderer(): AutoReplyRenderer
    {
        return $this->renderer ??= new AutoReplyRenderer($this->templatePath());
    }

    public function logger(): LoggerInterface
    {
        return $this->logger ??= new Logger('plead', [
            (new StreamHandler($this->paths->logFile(), $this->logLevel()))
                ->setFormatter(new ContextInterpolatingFormatter()),
        ]);
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function logLevel(): Level
    {
        $level = $this->logLevelOption;
        if (null === $level || $this->verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
            if ($this->verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                return Level::Debug;
            }
            try {
                $level = (string) $this->config()['log_level'];
            } catch (\Throwable) {
                $level = 'info';
            }
        }

        try {
            return Level::fromName(strtoupper($level));
        } catch (\Throwable) {
            return Level::Info;
        }
    }

    private function templatePath(): string
    {
        $configured = $this->config()['template']['auto_reply_path'];
        if (is_file($configured)) {
            return $configured;
        }

        $absolute = dirname(__DIR__, 2) . '/' . $configured;

        return is_file($absolute) ? $absolute : $configured;
    }
}
