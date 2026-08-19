<?php

declare(strict_types=1);

namespace App\Template;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AutoReplyRenderer
{
    private readonly Environment $twig;

    public function __construct(private readonly string $templatePath)
    {
        $this->twig = new Environment(
            new FilesystemLoader(dirname($templatePath)),
            ['autoescape' => false],
        );
    }

    /** @param array<string, mixed> $context */
    public function render(array $context): string
    {
        return trim($this->twig->render(basename($this->templatePath), $context));
    }
}
