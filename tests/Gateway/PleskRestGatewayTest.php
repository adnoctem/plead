<?php

declare(strict_types=1);

namespace App\Tests\Gateway;

use App\Gateway\PleskRestGateway;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class PleskRestGatewayTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> */
    private array $requests = [];

    public function testCliCommandsBuildsRequestAndParses(): void
    {
        $gateway = $this->gateway([[200, '["extension","domain","mail"]']]);

        $commands = $gateway->cliCommands();

        self::assertSame(['extension', 'domain', 'mail'], $commands);
        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://dellius.delta4x4.net:8443/api/v2/cli/commands', $this->requests[0]['url']);
        self::assertStringContainsString('X-API-Key: secret-key', implode("\n", $this->requests[0]['headers']));
    }

    public function testCliRefBuildsRequestAndParses(): void
    {
        $gateway = $this->gateway([[200, '{"allowed_commands":{"call":{"name":"call","usage":"--call <name> <command>"}}}']]);

        $ref = $gateway->cliRef('extension');

        self::assertArrayHasKey('allowed_commands', $ref);
        self::assertSame('--call <name> <command>', $ref['allowed_commands']['call']['usage']);
        self::assertSame('https://dellius.delta4x4.net:8443/api/v2/cli/extension/ref', $this->requests[0]['url']);
    }

    public function testCliCallBuildsJsonBodyAndParses(): void
    {
        $gateway = $this->gateway([[200, '{"code":0,"stdout":"Done","stderr":""}']]);

        $result = $gateway->cliCall('extension', ['--call', 'sslit', '--help']);

        self::assertSame(0, $result['code']);
        self::assertSame('Done', $result['stdout']);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://dellius.delta4x4.net:8443/api/v2/cli/extension/call', $this->requests[0]['url']);
        self::assertStringContainsString('Content-Type: application/json', implode("\n", $this->requests[0]['headers']));

        $body = json_decode((string) $this->requests[0]['body'], true);
        self::assertSame(['--call', 'sslit', '--help'], $body['params']);
        self::assertTrue($body['fail_on_error']);
    }

    public function testCliCallFailsOnNonZeroExit(): void
    {
        $gateway = $this->gateway([[200, '{"code":1,"stdout":"","stderr":"boom"}']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exited with code 1: boom');

        $gateway->cliCall('extension', ['--call', 'sslit']);
    }

    public function testCliCallWithoutFailOnErrorReturnsNonZeroResult(): void
    {
        $gateway = $this->gateway([[200, '{"code":3,"stdout":"partial","stderr":"warn"}']]);

        $result = $gateway->cliCall('extension', ['--list'], failOnError: false);

        self::assertSame(3, $result['code']);
        self::assertSame('partial', $result['stdout']);

        $body = json_decode((string) $this->requests[0]['body'], true);
        self::assertFalse($body['fail_on_error']);
    }

    public function testCliCallThrowsOnHttp422(): void
    {
        $gateway = $this->gateway([[422, '{"code":1,"stdout":"","stderr":"Execution failed"}']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Execution failed');

        $gateway->cliCall('extension', ['--call', 'sslit']);
    }

    public function testCliCallDryRunSkipsHttp(): void
    {
        $gateway = new PleskRestGateway(
            'dellius.delta4x4.net',
            8443,
            'https',
            'secret-key',
            true,
            new Logger('test'),
            function (): array {
                $this->fail('dry-run must not hit the transport');
            },
        );

        $result = $gateway->cliCall('extension', ['--call', 'sslit']);

        self::assertSame(0, $result['code']);
        self::assertSame([], $this->requests);
    }

    public function testInvalidJsonRaisesError(): void
    {
        $gateway = $this->gateway([[200, 'not-json']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $gateway->cliCommands();
    }

    /**
     * @param array<int, array{0: int, 1: string}> $responses
     */
    private function gateway(array $responses): PleskRestGateway
    {
        $this->requests = [];

        return new PleskRestGateway(
            'dellius.delta4x4.net',
            8443,
            'https',
            'secret-key',
            false,
            new Logger('test'),
            function (string $method, string $url, array $headers, ?string $body) use ($responses): array {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                $response = array_shift($responses) ?? [200, '[]'];

                return [$response[0], $response[1]];
            },
        );
    }
}
