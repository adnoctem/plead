<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\AutoReplyRenderer;
use PHPUnit\Framework\TestCase;

final class AutoReplyRendererTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/plead-template-test-' . bin2hex(random_bytes(4));
        mkdir($this->templateDir, 0777, true);
        file_put_contents($this->templateDir . '/reply.txt.twig', "Subject: {{ subject }}\n\n{{ message }}\n{{ date|date('Y-m-d') }}");
    }

    public function testAutoescapeIsDisabledForPlainTextMessages(): void
    {
        $renderer = new AutoReplyRenderer($this->templateDir . '/reply.txt.twig');

        $output = $renderer->render([
            'subject' => 'Out of office & more \' details',
            'message' => 'Ich bin bis zum 30.08. nicht erreichbar. Grüße & Kuss, Ärgerlich \'so\'',
            'date' => new \DateTimeImmutable('2026-08-19'),
        ]);

        self::assertStringContainsString('Out of office & more \' details', $output);
        self::assertStringContainsString('Grüße & Kuss, Ärgerlich \'so\'', $output);
        self::assertStringNotContainsString('&amp;', $output);
        self::assertStringNotContainsString('&#039;', $output);
        self::assertStringContainsString('2026-08-19', $output);
    }

    public function testMissingTemplateThrows(): void
    {
        $renderer = new AutoReplyRenderer($this->templateDir . '/nope.txt.twig');

        $this->expectException(\Twig\Error\LoaderError::class);

        $renderer->render(['message' => 'x']);
    }
}
