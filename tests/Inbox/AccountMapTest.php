<?php

declare(strict_types=1);

namespace App\Tests\Inbox;

use App\Inbox\AccountMap;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AccountMapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/plead-account-map-test-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testMissingMapIsEmpty(): void
    {
        $map = AccountMap::fromDirectory($this->directory);

        self::assertFalse($map->hasEntries());
        self::assertNull($map->lookup('CFischer'));
    }

    public function testLookupIsCaseInsensitiveAndToleratesBom(): void
    {
        file_put_contents(
            $this->directory.'/'.AccountMap::FILENAME,
            "\xEF\xBB\xBF".json_encode(['CFischer' => 'christopher.fischer@example.com'], JSON_THROW_ON_ERROR),
        );

        $map = AccountMap::fromDirectory($this->directory);

        self::assertTrue($map->hasEntries());
        self::assertSame('christopher.fischer@example.com', $map->lookup('cfischer'));
        self::assertSame('christopher.fischer@example.com', $map->lookup('CFISCHER'));
    }

    public function testEmptyObjectHasNoEntries(): void
    {
        file_put_contents($this->directory.'/'.AccountMap::FILENAME, '{}');

        self::assertFalse(AccountMap::fromDirectory($this->directory)->hasEntries());
    }

    public function testInvalidJsonIsRejected(): void
    {
        file_put_contents($this->directory.'/'.AccountMap::FILENAME, '{ not json');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid JSON');

        AccountMap::fromDirectory($this->directory);
    }

    public function testTopLevelListIsRejected(): void
    {
        file_put_contents($this->directory.'/'.AccountMap::FILENAME, '["a@example.com"]');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON object');

        AccountMap::fromDirectory($this->directory);
    }

    public function testNonStringEntryIsRejected(): void
    {
        file_put_contents($this->directory.'/'.AccountMap::FILENAME, '{"CFischer": 42}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');

        AccountMap::fromDirectory($this->directory);
    }

    public function testInvalidSentinelIsMarked(): void
    {
        self::assertTrue(AccountMap::invalid()->isInvalid());
        self::assertFalse(AccountMap::empty()->isInvalid());
    }

    public function testMapFileDetectionIsCaseInsensitive(): void
    {
        self::assertTrue(AccountMap::isMapFile('/inbox/'.AccountMap::FILENAME));
        self::assertTrue(AccountMap::isMapFile('/inbox/AD-ACCOUNT-MAP.JSON'));
        self::assertFalse(AccountMap::isMapFile('/inbox/user.json'));
    }
}
