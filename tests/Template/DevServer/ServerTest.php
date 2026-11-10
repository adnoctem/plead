<?php

declare(strict_types=1);

namespace App\Tests\Template\DevServer;

use App\Template\DevServer\Server;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class ServerTest extends TestCase
{
    private string $base;
    private string $template;
    private string $dataFile;
    private string $configFile;
    private Server $server;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-dev-server-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/templates', 0o777, true);
        mkdir($this->base.'/translations', 0o777, true);

        $this->template = $this->base.'/templates/message.txt.twig';
        file_put_contents($this->template, "{{ 'absence'|trans({}, 'autoresponder', language) }}");

        file_put_contents($this->base.'/translations/autoresponder.de.yaml', 'absence: "nicht weitergeleitet"');

        $this->dataFile = $this->base.'/data.yaml';
        file_put_contents($this->dataFile, 'firstname: Erika');

        $this->configFile = $this->base.'/plead.yaml';
        file_put_contents($this->configFile, implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'template:',
            '    paths:',
            '        auto_reply: '.$this->template,
            '        translations: '.$this->base.'/translations',
            '    language: de',
            'watch:',
            '    inbox:',
            '        template: '.$this->template,
            '        translations: '.$this->base.'/translations',
            '        data_file: '.$this->dataFile,
            '',
        ]));

        $this->server = new Server([
            'config_file' => $this->configFile,
            'inbox' => true,
            'template' => $this->template,
            'data_file' => $this->dataFile,
            'translations' => $this->base.'/translations',
            'logo_url' => null,
            'language' => 'de',
            'deputy_email' => null,
            'start_date' => '2026-11-10T00:00:00+01:00',
            'end_date' => '2026-11-17T23:59:59+01:00',
            'inclusive' => true,
            'watch' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->base);
    }

    public function testRenderReturnsSourceAndHtml(): void
    {
        $body = $this->request('/api/render', ['language' => 'de']);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['ok']);
        self::assertSame('text', $data['kind']);
        self::assertStringContainsString('nicht weitergeleitet', $data['source']);
        self::assertSame($data['source'], $data['html']);
    }

    public function testStateListsTemplatesDataFilesAndLanguages(): void
    {
        $body = $this->request('/api/state', ['language' => 'de']);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['ok']);
        self::assertContains('de', $data['languages']);
        self::assertNotEmpty($data['templates']);
        self::assertNotEmpty($data['dataFiles']);
        self::assertTrue($data['watch']);
    }

    public function testUnknownTemplateIsRejected(): void
    {
        $body = $this->request('/api/render', ['template' => '/etc/passwd']);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['ok']);
        self::assertStringContainsString('Unknown template', $data['error']);
    }

    public function testIndexAndUnknownPath(): void
    {
        $body = $this->request('/');
        self::assertStringContainsString('template studio', $body);

        $body = $this->request('/does-not-exist');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['ok']);
    }

    /** @param array<string, mixed> $get */
    private function request(string $path, array $get = []): string
    {
        ob_start();
        $this->server->handle(['REQUEST_URI' => $path], $get);
        $body = ob_get_clean();

        return false === $body ? '' : $body;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
