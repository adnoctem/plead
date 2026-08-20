<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigLoader;
use App\Config\PathProvider\PathProviderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * @internal
 */
#[CoversNothing]
final class ConfigLoaderTest extends TestCase
{
    private string $systemDir;
    private string $userDir;
    private ConfigLoader $loader;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/plead-config-test-'.bin2hex(random_bytes(4));
        $this->systemDir = $base.'/system';
        $this->userDir = $base.'/user';
        mkdir($this->systemDir, 0o777, true);
        mkdir($this->userDir, 0o777, true);

        $paths = new class($this->systemDir, $this->userDir) implements PathProviderInterface {
            public function __construct(
                private readonly string $systemDir,
                private readonly string $userDir,
            ) {}

            public function configHome(): string
            {
                return $this->userDir;
            }

            public function configDirs(): array
            {
                return [$this->userDir, $this->systemDir];
            }

            public function configPaths(): array
            {
                return [$this->userDir.'/plead.yaml', $this->userDir.'/plead.json', $this->systemDir.'/plead.yaml', $this->systemDir.'/plead.json'];
            }

            public function dataHome(): string
            {
                return sys_get_temp_dir();
            }

            public function dataDir(): string
            {
                return sys_get_temp_dir();
            }

            public function cacheHome(): string
            {
                return sys_get_temp_dir();
            }

            public function logFile(): string
            {
                return sys_get_temp_dir().'/plead.log';
            }
        };

        $this->loader = new ConfigLoader($paths);
    }

    public function testUserConfigWinsOverSystemWide(): void
    {
        file_put_contents($this->systemDir.'/plead.yaml', "plesk:\n    host: system.example.com\n    secret_key: system-key\n");
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: user.example.com\n    secret_key: user-key\n");

        $config = $this->loader->load();

        self::assertSame('user.example.com', $config['plesk']['host']);
        self::assertSame('user-key', $config['plesk']['secret_key']);
    }

    public function testMissingFilesAreSkippedAndDefaultsApply(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: only-host.example.com\n    secret_key: k\n");

        $config = $this->loader->load();

        self::assertSame('info', $config['log_level']);
        self::assertSame('templates/auto-reply.txt.twig', $config['template']['auto_reply_path']);
    }

    public function testJsonAndYamlMerge(): void
    {
        file_put_contents($this->systemDir.'/plead.json', json_encode([
            'plesk' => ['host' => 'json.example.com', 'secret_key' => 'json-key'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->userDir.'/plead.yaml', "log_level: debug\n");

        $config = $this->loader->load();

        self::assertSame('json.example.com', $config['plesk']['host']);
        self::assertSame('debug', $config['log_level']);
    }

    public function testExplicitPathLoadsOnlyThatFile(): void
    {
        file_put_contents($this->systemDir.'/plead.yaml', "plesk:\n    host: system.example.com\n    secret_key: system-key\n");
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: user.example.com\n    secret_key: user-key\n");
        file_put_contents($this->userDir.'/custom.yaml', "plesk:\n    host: custom.example.com\n    secret_key: custom-key\n");

        $config = $this->loader->load($this->userDir.'/custom.yaml');

        self::assertSame('custom.example.com', $config['plesk']['host']);
    }

    public function testEnvironmentOverridesConfigFiles(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: user.example.com\n    secret_key: user-key\n");

        putenv('PLEAD_PLESK_HOST=env.example.com');

        try {
            $config = $this->loader->load();
        } finally {
            putenv('PLEAD_PLESK_HOST');
        }

        self::assertSame('env.example.com', $config['plesk']['host']);
        self::assertSame('user-key', $config['plesk']['secret_key']);
    }

    public function testMissingRequiredKeysThrow(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "log_level: debug\n");

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    public function testCredentialsOnlyConfigPasses(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: mail.company.com\n    login: admin\n    password: s3cret\n");

        $config = $this->loader->load();

        self::assertSame('admin', $config['plesk']['login']);
        self::assertSame('s3cret', $config['plesk']['password']);
        self::assertNull($config['plesk']['secret_key']);
    }

    public function testMissingBothAuthMethodsThrows(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: mail.company.com\n");

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('plesk.secret_key or plesk.login');

        $this->loader->load();
    }

    public function testPartialCredentialsThrow(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: mail.company.com\n    login: admin\n");

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    public function testCredentialsEnvOverridesApply(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: mail.company.com\n    login: file-admin\n    password: file-pass\n");

        putenv('PLEAD_PLESK_PASSWORD=env-pass');

        try {
            $config = $this->loader->load();
        } finally {
            putenv('PLEAD_PLESK_PASSWORD');
        }

        self::assertSame('env-pass', $config['plesk']['password']);
    }
}
