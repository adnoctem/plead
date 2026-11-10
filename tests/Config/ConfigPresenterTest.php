<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigPresenter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class ConfigPresenterTest extends TestCase
{
    public function testFoldsTheSelectedServerAndMasksCredentials(): void
    {
        $config = [
            'servers' => [
                ['host' => 'one.example.com', 'secret_key' => 'one-secret', 'login' => null, 'password' => null],
                ['host' => 'two.example.com', 'secret_key' => 'file-secret', 'login' => 'file-login', 'password' => null],
            ],
            'server' => ['host' => 'two.example.com', 'secret_key' => 'env-secret', 'login' => 'env-login', 'password' => null],
            'plesk' => ['host' => 'two.example.com', 'secret_key' => 'env-secret', 'login' => 'env-login', 'password' => null],
            'default_server' => 'one.example.com',
            'mail' => ['group' => []],
            'watch' => ['interval' => 60],
            'template' => ['language' => 'de'],
            'log_level' => 'debug',
        ];

        $output = ConfigPresenter::forOutput($config);

        self::assertArrayNotHasKey('server', $output);
        self::assertArrayNotHasKey('plesk', $output);
        self::assertSame('two.example.com', $output['selected_server']);
        self::assertSame('one.example.com', $output['default_server']);
        self::assertSame('debug', $output['log_level']);
        self::assertSame('one.example.com', $output['servers'][0]['host']);
        self::assertSame('two.example.com', $output['servers'][1]['host']);
        self::assertSame('env-login', $output['servers'][1]['login']);
        self::assertSame('***', $output['servers'][0]['secret_key']);
        self::assertSame('***', $output['servers'][1]['secret_key']);
    }
}
