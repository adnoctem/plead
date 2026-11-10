<?php

declare(strict_types=1);

namespace App\Command\Template;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'template:serve', description: 'Serve a hot-reloading local template preview')]
final class TemplateServeCommand extends AbstractTemplateCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind address (default: 127.0.0.1)', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'HTTP port (default: 8080)', '8080')
            ->addOption('open', null, InputOption::VALUE_NONE, 'Open the studio in the browser')
            ->addOption('no-watch', null, InputOption::VALUE_NONE, 'Disable automatic preview refresh')
        ;
        $this->configureTemplateOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $studio = $this->studio();
            $request = $this->request($input);
            $studio->render($request);
        } catch (\Throwable $e) {
            return $this->fail($output, $e);
        }

        $host = trim((string) ($input->getOption('host') ?: '127.0.0.1'));
        if ('' === $host || 1 !== preg_match('/^[A-Za-z0-9.:-]+$/', $host)) {
            $output->writeln('<error>Invalid --host value.</error>');

            return self::FAILURE;
        }

        $port = (int) ($input->getOption('port') ?: 8080);
        if ($port < 1 || $port > 65535) {
            $output->writeln('<error>--port must be between 1 and 65535.</error>');

            return self::FAILURE;
        }

        $probe = @stream_socket_server('tcp://'.$host.':'.$port, $errorCode, $errorMessage);
        if (false === $probe) {
            $output->writeln(sprintf(
                '<error>Cannot bind %s:%d: %s</error>',
                $host,
                $port,
                '' !== $errorMessage ? $errorMessage : 'port is unavailable',
            ));

            return self::FAILURE;
        }
        fclose($probe);

        $configOption = $input->getOption('config');
        $state = [
            'config_file' => is_string($configOption) && '' !== $configOption ? $configOption : null,
            'inbox' => (bool) $input->getOption('inbox'),
            'template' => $request->templatePath,
            'data_file' => $request->dataFile,
            'translations' => $request->translationsPath,
            'logo_url' => $request->logoUrl,
            'language' => $request->language,
            'deputy_email' => $request->deputyEmail,
            'start_date' => $request->startDate,
            'end_date' => $request->endDate,
            'inclusive' => $request->inclusive,
            'watch' => !(bool) $input->getOption('no-watch'),
        ];

        $tempDirectory = sys_get_temp_dir().'/plead-template-'.bin2hex(random_bytes(6));
        if (!mkdir($tempDirectory, 0o700, true)) {
            $output->writeln(sprintf('<error>Unable to create temporary directory: %s</error>', $tempDirectory));

            return self::FAILURE;
        }

        $stateFile = $tempDirectory.'/state.json';
        $routerFile = $tempDirectory.'/router.php';

        try {
            file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($routerFile, $this->routerBootstrap());
        } catch (\Throwable $e) {
            $this->removeDirectory($tempDirectory);

            return $this->fail($output, $e);
        }

        $bind = $host.':'.$port;
        $url = sprintf('http://%s:%d', '0.0.0.0' === $host ? '127.0.0.1' : $host, $port);

        if ('0.0.0.0' === $host) {
            $output->writeln('<comment>Binding to 0.0.0.0 exposes the studio on the network; use only on trusted hosts.</comment>');
        }

        $output->writeln(sprintf('Template studio: <info>%s</info>', $url));
        $output->writeln(sprintf('Watching: <info>%s</info>', $state['watch'] ? 'on' : 'off'));
        $output->writeln('Press Ctrl+C to stop.');

        $process = new Process([PHP_BINARY, '-S', $bind, $routerFile]);
        $process->setTimeout(null);
        $env = getenv();
        $env['PLEAD_TEMPLATE_SERVE_STATE'] = $stateFile;
        $process->setEnv($env);

        $stopped = false;
        $stop = static function () use ($process, &$stopped): void {
            if ($process->isRunning()) {
                $stopped = true;
                $process->stop(0);
            }
        };
        register_shutdown_function(static function () use ($stop, $tempDirectory): void {
            $stop();
            self::removeDirectory($tempDirectory);
        });

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, static function () use ($stop): void {
                    $stop();
                });
            }
            if (defined('SIGTERM')) {
                pcntl_signal(SIGTERM, static function () use ($stop): void {
                    $stop();
                });
            }
        }

        $exitCode = 1;

        try {
            $process->start(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            if ((bool) $input->getOption('open')) {
                usleep(300_000);
                if (!$this->openTarget($url)) {
                    $output->writeln(sprintf('<comment>Open this URL manually: %s</comment>', $url));
                }
            }

            $exitCode = $process->wait();
        } finally {
            $stop();
            $this->removeDirectory($tempDirectory);
        }

        if (0 !== $exitCode && !$stopped && !in_array($exitCode, [130, 143, -1], true)) {
            $output->writeln(sprintf('<error>Template studio exited with code %d.</error>', $exitCode));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function routerBootstrap(): string
    {
        $phar = \Phar::running(false);
        $root = '' !== $phar ? 'phar://'.$phar : dirname(__DIR__, 3);
        $autoload = $root.'/vendor/autoload.php';

        return "<?php\n"
            .'require '.var_export($autoload, true).";\n"
            ."(new \\App\\Template\\DevServer\\Router(getenv('PLEAD_TEMPLATE_SERVE_STATE') ?: null))->handle();\n";
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
