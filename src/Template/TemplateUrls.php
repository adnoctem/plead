<?php

declare(strict_types=1);

namespace App\Template;

/**
 * The social networks supported by MJML's mj-social component. Networks with
 * a share URL append the configured href to a share link by default; the
 * template uses the "-noshare" suffix so template.urls values remain literal
 * profile/company links.
 */
final class TemplateUrls
{
    public const SHARE_NETWORKS = [
        'facebook',
        'twitter',
        'x',
        'google',
        'pinterest',
        'linkedin',
        'tumblr',
        'xing',
    ];

    public const NO_SHARE_NETWORKS = [
        'github',
        'instagram',
        'web',
        'snapchat',
        'youtube',
        'vimeo',
        'medium',
        'soundcloud',
        'dribbble',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(['logo'], self::SHARE_NETWORKS, self::NO_SHARE_NETWORKS);
    }

    public static function isAllowed(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
