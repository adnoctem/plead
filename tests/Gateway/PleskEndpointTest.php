<?php

declare(strict_types=1);

namespace App\Tests\Gateway;

use App\Gateway\PleskEndpoint;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class PleskEndpointTest extends TestCase
{
    public function testBareHostDefaultsToHttpsPort8443(): void
    {
        $endpoint = PleskEndpoint::fromConfig('mail.company.com');

        self::assertSame('mail.company.com', $endpoint->host);
        self::assertSame(8443, $endpoint->port);
        self::assertSame('https', $endpoint->protocol);
    }

    public function testHttpsUrlSchemeIsParsed(): void
    {
        $endpoint = PleskEndpoint::fromConfig('https://mail.company.com');

        self::assertSame('mail.company.com', $endpoint->host);
        self::assertSame('https', $endpoint->protocol);
    }

    public function testHttpUrlSchemeAndPortAreParsed(): void
    {
        $endpoint = PleskEndpoint::fromConfig('http://mail.company.com:8080');

        self::assertSame('mail.company.com', $endpoint->host);
        self::assertSame(8080, $endpoint->port);
        self::assertSame('http', $endpoint->protocol);
    }

    public function testHostWithPortOnlyIsParsed(): void
    {
        $endpoint = PleskEndpoint::fromConfig('mail.company.com:8444');

        self::assertSame('mail.company.com', $endpoint->host);
        self::assertSame(8444, $endpoint->port);
        self::assertSame('https', $endpoint->protocol);
    }

    public function testSchemeIsCaseInsensitive(): void
    {
        $endpoint = PleskEndpoint::fromConfig('HTTPS://mail.company.com');

        self::assertSame('https', $endpoint->protocol);
    }

    public function testEmptyHostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PleskEndpoint::fromConfig('https://');
    }
}
