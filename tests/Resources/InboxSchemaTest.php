<?php

declare(strict_types=1);

namespace App\Tests\Resources;

use App\Inbox\InboxEntry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class InboxSchemaTest extends TestCase
{
    public function testSchemaIsValidJsonAndItsExamplesParse(): void
    {
        $path = dirname(__DIR__, 2).'/resources/schema/inbox-entry.schema.json';
        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        self::assertSame('plead inbox absence entry (schema v1)', $schema['title'] ?? null);
        self::assertContains('cancel', $schema['properties']['action']['enum'] ?? []);
        self::assertContains('schedule', $schema['properties']['action']['enum'] ?? []);

        $examples = $schema['examples'] ?? [];
        self::assertCount(2, $examples);

        $entries = array_map(
            static fn (array $payload): InboxEntry => InboxEntry::fromPayload(
                $payload,
                'example.com',
                new \DateTimeZone('Europe/Berlin'),
            ),
            $examples,
        );

        self::assertSame(InboxEntry::ACTION_SCHEDULE, $entries[0]->action);
        self::assertSame('erika.mustermann@example.com', $entries[0]->email);
        self::assertSame(InboxEntry::ACTION_CANCEL, $entries[1]->action);
        self::assertSame('erika.mustermann@example.com', $entries[1]->email);
    }
}
