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
            'start_date' => '2026-08-20T08:00:00+02:00',
            'end_date' => '2026-08-30T18:00:00+02:00',
        ]);

        self::assertSame('user@company.com', $definition->email());
        self::assertSame('2026-08-20T08:00:00+02:00', $definition->startDate());
        self::assertSame('2026-08-30T18:00:00+02:00', $definition->endDate());
    }

    public function testYamlTimestampIntIsCoerced(): void
    {
        // Symfony's YAML parser turns unquoted ISO dates into Unix timestamps.
        // The instant is preserved; the offset normalizes to UTC.
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'start_date' => new \DateTimeImmutable('2099-01-01T08:00:00+02:00')->getTimestamp(),
            'end_date' => new \DateTimeImmutable('2099-01-05T18:00:00+02:00')->getTimestamp(),
        ]);

        self::assertSame('2099-01-01T06:00:00+00:00', $definition->startDate());
    }

    public function testStartDateIsOptional(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
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
            'end_date' => '2026-08-30 18:00',
        ]);
    }

    public function testSubjectExposedWhenSet(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'subject' => 'Automatische Antwort',
        ]);
        self::assertSame('Automatische Antwort', $definition->subject());

        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
        ]);
        self::assertNull($definition->subject());
    }

    public function testFormatExposedWhenSet(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'format' => 'html',
        ]);
        self::assertSame('html', $definition->format());
    }

    public function testInvalidFormatRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'format' => 'markdown',
        ]);
    }

    public function testLanguageExposedWhenSet(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'language' => 'en',
        ]);
        self::assertSame('en', $definition->language());

        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
        ]);
        self::assertNull($definition->language());
    }

    public function testEndDateRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
        ]);
    }

    public function testEndBeforeStartRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('after start_date');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'start_date' => '2026-08-30 18:00',
            'end_date' => '2026-08-20 08:00',
        ]);
    }

    public function testDataFileExposedWhenSet(): void
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'plead-data-');
        self::assertNotFalse($dataFile);

        try {
            $definition = AutoReplyDefinition::fromConfigEntry([
                'address' => 'user@company.com',
                'data_file' => $dataFile,
                'end_date' => '2026-08-30 18:00',
            ]);

            self::assertSame($dataFile, $definition->dataFile());
        } finally {
            @unlink($dataFile);
        }
    }

    public function testUnreadableDataFileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data_file');

        AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'data_file' => '/nonexistent/data.json',
            'end_date' => '2026-08-30 18:00',
        ]);
    }

    public function testInclusiveExposedWhenSet(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'inclusive' => true,
        ]);
        self::assertTrue($definition->inclusive());

        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
            'inclusive' => false,
        ]);
        self::assertFalse($definition->inclusive());
    }

    public function testInclusiveNullWithoutEntry(): void
    {
        $definition = AutoReplyDefinition::fromConfigEntry([
            'address' => 'user@company.com',
            'end_date' => '2026-08-30 18:00',
        ]);
        self::assertNull($definition->inclusive());
    }
}
