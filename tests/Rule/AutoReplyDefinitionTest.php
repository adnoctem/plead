<?php

declare(strict_types=1);

namespace App\Tests\Rule;

use App\Rule\AutoReplyDefinition;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AutoReplyDefinitionTest extends TestCase
{
    public function testValidEntryNormalizesDates(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'User@Company.com',
            'message_file' => '/tmp/message.txt',
            'start_date' => '2026-08-20T08:00:00+02:00',
            'end_date' => '2026-08-30T18:00:00+02:00',
        ]);

        self::assertSame('user@company.com', $definition->email());
        self::assertSame('/tmp/message.txt', $definition->messageFile());
        self::assertSame('2026-08-20T08:00:00+02:00', $definition->startDate());
        self::assertSame('2026-08-30T18:00:00+02:00', $definition->endDate());
    }

    public function testYamlTimestampIntIsCoerced(): void
    {
        // Symfony's YAML parser turns unquoted ISO dates into Unix timestamps.
        // The instant is preserved; the offset normalizes to UTC.
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'message_file' => '/tmp/message.txt',
            'start_date' => new \DateTimeImmutable('2099-01-01T08:00:00+02:00')->getTimestamp(),
            'end_date' => new \DateTimeImmutable('2099-01-05T18:00:00+02:00')->getTimestamp(),
        ]);

        self::assertSame('2099-01-01T06:00:00+00:00', $definition->startDate());
    }

    public function testStartDateIsOptional(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'message_file' => '/tmp/message.txt',
            'end_date' => '2026-08-30 18:00',
        ]);

        self::assertNull($definition->startDate());
    }

    public function testAddressMustBeFullEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('full email address');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user',
            'message_file' => '/tmp/message.txt',
            'end_date' => '2026-08-30 18:00',
        ]);
    }

    public function testMessageFileRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('message_file');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
        ]);
    }

    public function testEndDateRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'message_file' => '/tmp/message.txt',
        ]);
    }

    public function testEndBeforeStartRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('after start_date');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'message_file' => '/tmp/message.txt',
            'start_date' => '2026-08-30 18:00',
            'end_date' => '2026-08-20 08:00',
        ]);
    }
}
