<?php

declare(strict_types=1);

namespace App\Template;

/**
 * The two views of one render: the Twig output (MJML or text) and the final
 * HTML. For text templates source and html are identical.
 */
final class RenderedTemplate
{
    public const KIND_MJML = 'mjml';
    public const KIND_TEXT = 'text';

    /**
     * @param list<string> $languages
     */
    public function __construct(
        public readonly string $source,
        public readonly string $html,
        public readonly string $kind,
        public readonly string $templatePath,
        public readonly array $languages = [],
    ) {}

    public function isMjml(): bool
    {
        return self::KIND_MJML === $this->kind;
    }
}
