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
        file_put_contents($this->systemDir.'/plead.yaml', $this->serverYaml('system.example.com', 'system-key'));
        file_put_contents($this->userDir.'/plead.yaml', $this->serverYaml('user.example.com', 'user-key'));

        $config = $this->loader->load();

        self::assertSame('user.example.com', $config['server']['host']);
        self::assertSame('user-key', $config['server']['secret_key']);
        self::assertSame('user.example.com', $config['plesk']['host']);
    }

    public function testMissingFilesAreSkippedAndDefaultsApply(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', $this->serverYaml('only-host.example.com'));

        $config = $this->loader->load();

        self::assertSame('info', $config['log_level']);
        self::assertSame('templates/auto-reply.txt.twig', $config['template']['auto_reply_path']);
        self::assertNull($config['mail']['defaults']['antivirus']);
        self::assertNull($config['mail']['defaults']['quota']);
        self::assertSame(60, $config['watch']['interval']);
        self::assertNull($config['default_server']);
    }

    public function testJsonAndYamlMerge(): void
    {
        file_put_contents($this->systemDir.'/plead.json', json_encode([
            'servers' => [['host' => 'json.example.com', 'secret_key' => 'json-key']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->userDir.'/plead.yaml', "log_level: debug\n");

        $config = $this->loader->load();

        self::assertSame('json.example.com', $config['server']['host']);
        self::assertSame('debug', $config['log_level']);
    }

    public function testExplicitPathLoadsOnlyThatFile(): void
    {
        file_put_contents($this->systemDir.'/plead.yaml', $this->serverYaml('system.example.com'));
        file_put_contents($this->userDir.'/plead.yaml', $this->serverYaml('user.example.com'));
        file_put_contents($this->userDir.'/custom.yaml', $this->serverYaml('custom.example.com'));

        $config = $this->loader->load($this->userDir.'/custom.yaml');

        self::assertSame('custom.example.com', $config['server']['host']);
    }

    public function testDefaultsToFirstServer(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
        ]));

        $config = $this->loader->load();

        self::assertSame('first.example.com', $config['server']['host']);
        self::assertCount(2, $config['servers']);
    }

    public function testSelectsServerByHost(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
        ]));

        $config = $this->loader->load(null, 'second.example.com');

        self::assertSame('second.example.com', $config['server']['host']);
        self::assertSame('b', $config['server']['secret_key']);
    }

    public function testSelectsServerByIndex(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
        ]));

        self::assertSame('second.example.com', $this->loader->load(null, '1')['server']['host']);
    }

    public function testUnknownServerThrows(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', $this->serverYaml('only.example.com'));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('not configured');

        $this->loader->load(null, 'nope.example.com');
    }

    public function testPleadServerEnvSelectsServer(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
        ]));

        putenv('PLEAD_SERVER=second.example.com');

        try {
            $config = $this->loader->load();
        } finally {
            putenv('PLEAD_SERVER');
        }

        self::assertSame('second.example.com', $config['server']['host']);
    }

    public function testDefaultServerSelectsServer(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
            'default_server: second.example.com',
        ]));

        self::assertSame('second.example.com', $this->loader->load()['server']['host']);
    }

    public function testCliServerBeatsDefaultServer(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
            'default_server: second.example.com',
        ]));

        self::assertSame('first.example.com', $this->loader->load(null, 'first.example.com')['server']['host']);
    }

    public function testWatchIntervalFromConfig(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'watch:',
            '    interval: 300',
        ]));

        self::assertSame(300, $this->loader->load()['watch']['interval']);
    }

    public function testAutoresponderEntriesMergeByAddress(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
            'mail:',
            '    autoresponder:',
            '        - address: user@example.com',
            '          message_file: /tmp/general.txt',
            '          end_date: 2099-01-05T18:00:00+02:00',
            'second.example.com:',
            '    mail:',
            '        autoresponder:',
            '            - address: user@example.com',
            '              message_file: /tmp/server.txt',
            '              end_date: 2099-02-05T18:00:00+02:00',
            '            - address: other@example.com',
            '              message_file: /tmp/other.txt',
            '              end_date: 2099-03-05T18:00:00+02:00',
        ]));

        $first = $this->loader->load(null, 'first.example.com')['mail']['autoresponder'];
        self::assertCount(1, $first);
        self::assertSame('/tmp/general.txt', $first[0]['message_file']);

        $second = $this->loader->load(null, 'second.example.com')['mail']['autoresponder'];
        self::assertCount(2, $second);
        $byAddress = array_column($second, 'message_file', 'address');
        self::assertSame('/tmp/server.txt', $byAddress['user@example.com']);
        self::assertSame('/tmp/other.txt', $byAddress['other@example.com']);
    }

    public function testAutoresponderEntryRequiresFullAddress(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    autoresponder:',
            '        - address: user',
            '          message_file: /tmp/m.txt',
            '          end_date: 2099-01-05T18:00:00+02:00',
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('full email address');

        $this->loader->load();
    }

    public function testAutoresponderEntryRequiresEndDate(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    autoresponder:',
            '        - address: user@example.com',
            '          message_file: /tmp/m.txt',
        ]));

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    public function testPerServerSectionOverridesGeneralMail(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: first.example.com',
            '      secret_key: a',
            '    - host: second.example.com',
            '      secret_key: b',
            'mail:',
            '    defaults:',
            '        quota: 1GB',
            '    group:',
            '        - address: all@example.com',
            "          pattern: '^(info|support)@'",
            'second.example.com:',
            '    mail:',
            '        defaults:',
            '            quota: 512MB',
            '        group:',
            '            - address: all@example.com',
            "              pattern: '^(info)@'",
            '            - address: ops@example.com',
            "              pattern: '^(noreply)@'",
        ]));

        $first = $this->loader->load(null, 'first.example.com');
        self::assertSame(1073741824, $first['mail']['defaults']['quota']);
        self::assertSame(['all@example.com'], array_column($first['mail']['group'], 'address'));

        $second = $this->loader->load(null, 'second.example.com');
        self::assertSame(536870912, $second['mail']['defaults']['quota']);
        $groups = array_column($second['mail']['group'], 'address');
        self::assertSame(['all@example.com', 'ops@example.com'], $groups);
        $allEntry = $second['mail']['group'][array_search('all@example.com', $groups, true)];
        self::assertSame('^(info)@', $allEntry['pattern']);
    }

    public function testGroupEntriesMergeByAddressWithServerWinning(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    group:',
            '        - address: all@example.com',
            "          pattern: '^(info)@'",
            '        - address: team@example.com',
            '          recipients: [a@example.com]',
            'only.example.com:',
            '    mail:',
            '        group:',
            '            - address: all@example.com',
            "              pattern: '^(noreply)@'",
        ]));

        $config = $this->loader->load();
        $groups = $config['mail']['group'];

        self::assertCount(2, $groups);
        foreach ($groups as $entry) {
            if ('all@example.com' === $entry['address']) {
                self::assertSame('^(noreply)@', $entry['pattern']);
            }
        }
    }

    public function testDomainLessAddressNeedsDomain(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    group:',
            '        - address: all',
            "          pattern: '^(info)@'",
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('explicit domain');

        $this->loader->load();
    }

    public function testPatternAndRecipientsMayCombine(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    group:',
            '        - address: all@example.com',
            "          pattern: '^(info)@'",
            '          recipients: [consultant@external.com]',
        ]));

        $groups = $this->loader->load()['mail']['group'];

        self::assertCount(1, $groups);
        self::assertSame('^(info)@', $groups[0]['pattern']);
        self::assertSame(['consultant@external.com'], $groups[0]['recipients']);
    }

    public function testPatternOrRecipientsRequired(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    group:',
            '        - address: all@example.com',
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must set either');

        $this->loader->load();
    }

    public function testInvalidPatternRejected(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    group:',
            '        - address: all@example.com',
            "          pattern: '['",
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('valid PCRE');

        $this->loader->load();
    }

    public function testUnknownTopLevelSectionThrows(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail.example.com:',
            '    mail: {}',
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown top-level configuration key');

        $this->loader->load();
    }

    public function testLegacyPleskBlockRejected(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "plesk:\n    host: old.example.com\n    secret_key: k\n");

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no longer supported');

        $this->loader->load();
    }

    public function testMissingServersThrows(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "log_level: debug\n");

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    public function testServerWithoutAuthThrows(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "servers:\n    - host: mail.company.com\n");

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('secret_key or both login and password');

        $this->loader->load();
    }

    public function testCredentialsOnlyConfigPasses(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "servers:\n    - host: mail.company.com\n      login: admin\n      password: s3cret\n");

        $config = $this->loader->load();

        self::assertSame('admin', $config['server']['login']);
        self::assertSame('s3cret', $config['server']['password']);
        self::assertNull($config['server']['secret_key']);
        self::assertSame('admin', $config['plesk']['login']);
    }

    public function testPartialCredentialsThrow(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "servers:\n    - host: mail.company.com\n      login: admin\n");

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    public function testAuthEnvOverridesApply(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', "servers:\n    - host: mail.company.com\n      login: file-admin\n      password: file-pass\n");

        putenv('PLEAD_PLESK_PASSWORD=env-pass');

        try {
            $config = $this->loader->load();
        } finally {
            putenv('PLEAD_PLESK_PASSWORD');
        }

        self::assertSame('env-pass', $config['server']['password']);
        self::assertSame('env-pass', $config['plesk']['password']);
    }

    public function testQuotaParsesHumanSizes(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    defaults:',
            '        quota: 2 GB',
        ]));

        self::assertSame(2147483648, $this->loader->load()['mail']['defaults']['quota']);
    }

    public function testInvalidQuotaRejected(): void
    {
        file_put_contents($this->userDir.'/plead.yaml', implode("\n", [
            'servers:',
            '    - host: only.example.com',
            '      secret_key: a',
            'mail:',
            '    defaults:',
            '        quota: many',
        ]));

        $this->expectException(InvalidConfigurationException::class);

        $this->loader->load();
    }

    private function serverYaml(string $host, string $key = 'key'): string
    {
        return sprintf("servers:\n    - host: %s\n      secret_key: %s\n", $host, $key);
    }
}
