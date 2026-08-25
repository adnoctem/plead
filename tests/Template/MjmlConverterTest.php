<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\MjmlConverter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MjmlConverterTest extends TestCase
{
    private string $fakeBinary;

    protected function setUp(): void
    {
        $this->fakeBinary = sys_get_temp_dir().'/plead-mjml-bin-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeBinary);
    }

    public function testConvertsViaBinary(): void
    {
        $this->makeBinary("#!/bin/sh\nprintf '<html>%s</html>' \"$(cat)\"\n");
        $converter = new MjmlConverter($this->fakeBinary);

        self::assertSame('<html><mjml><mj-body/></mjml></html>', $converter->convert('<mjml><mj-body/></mjml>'));
    }

    public function testConversionFailureThrowsWithBinaryName(): void
    {
        $this->makeBinary("#!/bin/sh\necho 'boom' >&2\nexit 1\n");
        $converter = new MjmlConverter($this->fakeBinary);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $converter->convert('<mjml><mj-body/></mjml>');
    }

    public function testMissingBinaryThrows(): void
    {
        $converter = new MjmlConverter('/nonexistent/mjml');

        $this->expectException(\RuntimeException::class);

        $converter->convert('<mjml><mj-body/></mjml>');
    }

    private function makeBinary(string $body): void
    {
        file_put_contents($this->fakeBinary, $body);
        chmod($this->fakeBinary, 0o755);
    }
}
