<?php

declare(strict_types=1);

namespace App\Tests\Inbox;

use App\Inbox\InboxEntry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class InboxEntryTest extends TestCase
{
    private \DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->timezone = new \DateTimeZone('Europe/Berlin');
    }

    public function testSchedulesFullEntry(): void
    {
        $entry = $this->entry([
            'schemaVersion' => 1,
            'action' => 'schedule',
            'email' => 'Erika.Mustermann@Example.com',
            'startDate' => '2026-09-17',
            'startTime' => '17:00',
            'endDate' => '2026-09-22',
            'endTime' => '12:30',
            'deputyEmail' => 'Deputy@Example.com',
            'language' => 'de',
            'subject' => 'Automatische Antwort',
            'format' => 'html',
            'inclusive' => true,
            'data' => ['firstname' => 'Erika', 'department' => ['de' => 'Vertrieb']],
            'submittedAt' => '2026-09-17T16:57:46.987+02:00',
        ]);

        self::assertSame('schedule', $entry->action);
        self::assertSame('erika.mustermann@example.com', $entry->email);
        self::assertSame('2026-09-17T17:00:00+02:00', $entry->startDate);
        self::assertSame('2026-09-22T12:30:00+02:00', $entry->endDate);
        self::assertSame('deputy@example.com', $entry->deputyEmail);
        self::assertSame('de', $entry->language);
        self::assertSame('Automatische Antwort', $entry->subject);
        self::assertSame('html', $entry->format);
        self::assertTrue($entry->inclusive);
        self::assertSame(['firstname' => 'Erika', 'department' => ['de' => 'Vertrieb']], $entry->data);
        self::assertSame('2026-09-17T16:57:46.987+02:00', $entry->submittedAt);
    }

    public function testDefaultsToWholeDays(): void
    {
        $entry = $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);

        self::assertSame('2026-09-17T00:00:00+02:00', $entry->startDate);
        self::assertSame('2026-09-18T23:59:59+02:00', $entry->endDate);
    }

    public function testSameDayAbsenceIsAllowed(): void
    {
        $entry = $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-10-20',
            'startTime' => '14:30',
            'endDate' => '2026-10-20',
        ]);

        self::assertSame('2026-10-20T14:30:00+02:00', $entry->startDate);
        self::assertSame('2026-10-20T23:59:59+02:00', $entry->endDate);
    }

    public function testSamAccountNameUsesEntryDomain(): void
    {
        $entry = $this->entry([
            'samAccountName' => 'CFischer',
            'domain' => 'Delta4x4.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ], 'ignored.example');

        self::assertSame('cfischer@delta4x4.com', $entry->email);
        self::assertSame('CFischer', $entry->samAccountName);
    }

    public function testSamAccountNameFallsBackToConfiguredDomain(): void
    {
        $entry = $this->entry([
            'samAccountName' => 'RHippmann',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ], 'delta4x4.de');

        self::assertSame('rhippmann@delta4x4.de', $entry->email);
    }

    public function testEmailWinsOverSamAccountName(): void
    {
        $entry = $this->entry([
            'email' => 'direct@example.com',
            'samAccountName' => 'SomeOne',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);

        self::assertSame('direct@example.com', $entry->email);
    }

    public function testCancelEntryNeedsIdentityOnly(): void
    {
        $entry = $this->entry([
            'action' => 'cancel',
            'email' => 'user@example.com',
        ]);

        self::assertSame('cancel', $entry->action);
        self::assertNull($entry->startDate);
        self::assertNull($entry->endDate);
        self::assertNull($entry->deputyEmail);
    }

    public function testEntryTimezoneOverridesTheDefault(): void
    {
        $entry = $this->entry([
            'email' => 'user@example.com',
            'timezone' => 'America/New_York',
            'startDate' => '2026-09-17',
            'startTime' => '09:00',
            'endDate' => '2026-09-18',
        ]);

        self::assertSame('2026-09-17T09:00:00-04:00', $entry->startDate);
    }

    public function testUnknownSchemaVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported schemaVersion 2');

        $this->entry([
            'schemaVersion' => 2,
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testNonIntegerSchemaVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schemaVersion must be an integer');

        $this->entry([
            'schemaVersion' => 'one',
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('action must be');

        $this->entry(['action' => 'postpone', 'email' => 'user@example.com']);
    }

    public function testMissingIdentityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('either "email" or "samAccountName" is required');

        $this->entry(['startDate' => '2026-09-17', 'endDate' => '2026-09-18']);
    }

    public function testSamAccountNameWithoutDomainIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a "domain"');

        $this->entry([
            'samAccountName' => 'CFischer',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ], null);
    }

    public function testImpossibleCalendarDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a real calendar date');

        $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-02-30',
            'endDate' => '2026-03-01',
        ]);
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ends');

        $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-09-20',
            'startTime' => '10:00',
            'endDate' => '2026-09-20',
            'endTime' => '09:00',
        ]);
    }

    public function testInvalidTimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('startTime must be');

        $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'startTime' => '25:00',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid IANA timezone');

        $this->entry([
            'email' => 'user@example.com',
            'timezone' => 'Mars/Olympus',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testDeputyMustDifferFromTheMailbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ');

        $this->entry([
            'email' => 'user@example.com',
            'deputyEmail' => 'USER@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be');

        $this->entry([
            'email' => 'user@example.com',
            'format' => 'markdown',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]);
    }

    public function testInvalidDataKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid template variable name');

        $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
            'data' => ['not a key' => 'x'],
        ]);
    }

    public function testInvalidSubmittedAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('submittedAt');

        $this->entry([
            'email' => 'user@example.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
            'submittedAt' => 'not-a-timestamp',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function entry(array $payload, ?string $domain = 'example.com'): InboxEntry
    {
        return InboxEntry::fromPayload($payload, $domain, $this->timezone);
    }
}
