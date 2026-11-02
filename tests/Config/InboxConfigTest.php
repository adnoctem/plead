<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\PleadConfiguration;
use App\Inbox\InboxSettings;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @internal
 */
#[CoversNothing]
final class InboxConfigTest extends TestCase
{
    public function testInboxIsDisabledByDefault(): void
    {
        $config = $this->resolve($this->servers());

        self::assertNull($config['watch']['inbox']['path']);
        self::assertNull(InboxSettings::fromConfig($config));
    }

    public function testInboxDefaultsAndArchiveLocations(): void
    {
        $config = $this->resolve($this->servers() + [
            'watch' => ['inbox' => ['path' => '/srv/inbox', 'timezone' => 'Europe/Berlin']],
        ]);

        self::assertSame('resources/templates/autoresponder-forwarding.txt.twig', $config['watch']['inbox']['template_forwarding']);
        self::assertTrue($config['watch']['inbox']['inclusive']);

        $settings = InboxSettings::fromConfig($config);
        self::assertNotNull($settings);
        self::assertSame('/srv/inbox', $settings->directory);
        self::assertSame('/srv/inbox/processed', $settings->processedDirectory);
        self::assertSame('/srv/inbox/failed', $settings->failedDirectory);
        self::assertSame('Europe/Berlin', $settings->timezone->getName());
    }

    public function testRelativeArchivePathsResolveAgainstTheInbox(): void
    {
        $config = $this->resolve($this->servers() + [
            'watch' => ['inbox' => [
                'path' => '/srv/inbox',
                'processed_path' => 'done',
                'failed_path' => '/var/tmp/plead-failed',
            ]],
        ]);

        $settings = InboxSettings::fromConfig($config);
        self::assertNotNull($settings);
        self::assertSame('/srv/inbox/done', $settings->processedDirectory);
        self::assertSame('/var/tmp/plead-failed', $settings->failedDirectory);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('watch.inbox.format');

        $this->resolve($this->servers() + [
            'watch' => ['inbox' => ['path' => '/srv/inbox', 'format' => 'markdown']],
        ]);
    }

    public function testInvalidTimezoneIsRejectedWhenBuildingSettings(): void
    {
        $config = $this->resolve($this->servers() + [
            'watch' => ['inbox' => ['path' => '/srv/inbox', 'timezone' => 'Mars/Olympus']],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid IANA timezone');

        InboxSettings::fromConfig($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function resolve(array $config): array
    {
        return new Processor()->processConfiguration(new PleadConfiguration(), [$config]);
    }

    /** @return array<string, mixed> */
    private function servers(): array
    {
        return ['servers' => [['host' => 'mail.example.com', 'secret_key' => 'key']]];
    }
}
