<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\MjmlConverter;
use App\Tests\Support\FakeBin;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MjmlConverterTest extends TestCase
{
    private string $fixtureDir;
    private string $fakeBinary;

    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__).'/Support/fake-bin';
        $this->fakeBinary = sys_get_temp_dir().'/plead-mjml-bin-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeBinary);
        @unlink($this->fakeBinary.'.cmd');
    }

    public function testConvertsViaBinary(): void
    {
        $binary = FakeBin::install($this->fixtureDir.'/mjml-plain.php', $this->fakeBinary);
        $converter = new MjmlConverter($binary);

        self::assertSame('<html><mjml><mj-body/></mjml></html>', $converter->convert('<mjml><mj-body/></mjml>'));
    }

    public function testConversionFailureThrowsWithBinaryName(): void
    {
        $binary = FakeBin::install($this->fixtureDir.'/mjml-failing.php', $this->fakeBinary);
        $converter = new MjmlConverter($binary);

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
}
