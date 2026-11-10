<?php

declare(strict_types=1);

namespace App\Template;

/**
 * One template preview request. Paths in this object are already resolved to
 * absolute paths by TemplateStudio.
 */
final class TemplateRenderRequest
{
    /**
     * @param array<string, mixed> $extraData inline template variables (e.g. one inbox entry)
     */
    public function __construct(
        public readonly string $templatePath,
        public readonly ?string $dataFile = null,
        public readonly ?string $translationsPath = null,
        public readonly ?string $logoUrl = null,
        public readonly ?string $language = null,
        public readonly ?string $deputyEmail = null,
        public readonly string $startDate = '',
        public readonly string $endDate = '',
        public readonly bool $inclusive = true,
        public readonly array $extraData = [],
    ) {}
}
