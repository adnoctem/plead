<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Shapes the resolved configuration for `config:list`. The internal array also
 * carries the selected `server` copy and the synthesized `plesk` block used by
 * the gateways; both are redundant with the `servers` list and must not leak
 * into the user-facing output. Credentials are always masked.
 */
final class ConfigPresenter
{
    /**
     * @param array<string, mixed> $config the merged configuration
     *
     * @return array<string, mixed>
     */
    public static function forOutput(array $config): array
    {
        $servers = is_array($config['servers'] ?? null) ? $config['servers'] : [];
        $selected = is_array($config['server'] ?? null) ? $config['server'] : null;

        // Fold the effective selected entry (including PLEAD_* auth overrides)
        // into the servers list, so there is no duplicate `server` block.
        if (null !== $selected && isset($selected['host'])) {
            foreach ($servers as $index => $server) {
                if (is_array($server) && isset($server['host'])
                    && 0 === strcasecmp((string) $server['host'], (string) $selected['host'])) {
                    $servers[$index] = $selected;

                    break;
                }
            }
        }

        $servers = SecretMasker::mask($servers);

        return [
            'servers' => $servers,
            'selected_server' => null !== $selected ? ($selected['host'] ?? null) : null,
            'default_server' => $config['default_server'] ?? null,
            'mail' => $config['mail'] ?? [],
            'watch' => $config['watch'] ?? [],
            'template' => $config['template'] ?? [],
            'log_level' => $config['log_level'] ?? 'info',
        ];
    }
}
