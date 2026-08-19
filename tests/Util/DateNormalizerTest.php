<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\DateNormalizer;
use PHPUnit\Framework\TestCase;

final class DateNormalizerTest extends TestCase
{
    public function testNormalizesIsoWithOffsetUnchanged(): void
    {
        $normalized = DateNormalizer::normalize('2026-08-19T08:00:00+02:00');

        self::assertSame('2026-08-19T08:00:00+02:00', $normalized);
    }

    public function testNormalizesUtcZulu(): void
    {
        $normalized = DateNormalizer::normalize('2026-08-19T08:00:00Z');

        self::assertSame('2026-08-19T08:00:00+00:00', $normalized);
    }

    public function testNormalizesNaiveStringWithLocalOffset(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $normalized = DateNormalizer::normalize('2026-08-19 08:00');

        self::assertSame('2026-08-19T08:00:00+02:00', $normalized);
    }

    public function testNormalizesDateOnlyToMidnight(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $normalized = DateNormalizer::normalize('2026-12-01');

        self::assertSame('2026-12-01T00:00:00+01:00', $normalized);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DateNormalizer::normalize('not-a-date');
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DateNormalizer::normalize('');
    }
}
