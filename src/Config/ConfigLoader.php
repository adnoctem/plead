<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\PathProvider\PathProviderInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigLoader
{
    /** Top-level keys that belong to the schema; anything else names a server section. */
    private const SCHEMA_KEYS = ['servers', 'mail', 'template', 'log_level'];

    public function __construct(private readonly PathProviderInterface $paths) {}

    /**
     * Resolved, validated configuration for the selected server. The result
     * carries the full 'servers' list, the selected 'server' entry, a
     * synthesized 'plesk' block (host + auth) and the merged 'mail' config
     * (general defaults overlaid with the selected server's section).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidConfigurationException when required keys are missing
     */
    public function load(?string $explicitPath = null, ?string $serverOption = null): array
    {
        $raw = [];
        foreach ($this->candidates($explicitPath) as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $raw[] = $this->parse($candidate);
        }

        $this->rejectLegacyConfig($raw);

        // Prototyped lists (servers, mail.group) concatenate across config
        // layers, which is surprising for "later file wins". Collapse them
        // into a single final layer that replaces everything earlier.
        $configs = array_reverse($this->collapseListKeys($raw));

        $processed = new Processor()->processConfiguration(new PleadConfiguration(), $configs);

        $servers = $processed['servers'];
        $selectedIndex = $this->resolveServerIndex($servers, $serverOption);
        $selected = $this->applyAuthOverrides($servers[$selectedIndex]);

        $sections = $this->collectServerSections($raw, $servers);

        return [
            'servers' => $servers,
            'server' => $selected,
            'plesk' => [
                'host' => $selected['host'],
                'secret_key' => $selected['secret_key'],
                'login' => $selected['login'],
                'password' => $selected['password'],
            ],
            'mail' => $this->mergeMail($processed['mail'] ?? [], $sections[$selected['host']] ?? []),
            'template' => $processed['template'],
            'log_level' => $processed['log_level'],
        ];
    }

    /**
     * Strip list-valued keys from every layer and append a final layer with
     * the highest-precedence values (first in discovery order - the user
     * config precedes system-wide files), so a lower-precedence file replaces
     * the servers and mail.group lists of higher-precedence files.
     *
     * @param array<int, array<string, mixed>> $rawFiles
     *
     * @return array<int, array<string, mixed>>
     */
    private function collapseListKeys(array $rawFiles): array
    {
        $layers = [];
        $servers = null;
        $groups = null;
        foreach ($rawFiles as $file) {
            $layer = $file;
            if (isset($layer['servers'])) {
                $servers ??= $layer['servers'];
                unset($layer['servers']);
            }
            if (isset($layer['mail']['group'])) {
                $groups ??= $layer['mail']['group'];
                unset($layer['mail']['group']);
                if ([] === $layer['mail']) {
                    unset($layer['mail']);
                }
            }
            $layers[] = $layer;
        }

        $final = [];
        if (null !== $servers) {
            $final['servers'] = $servers;
        }
        if (null !== $groups) {
            $final['mail']['group'] = $groups;
        }
        $layers[] = $final;

        return $layers;
    }

    /** @param array<int, array<string, mixed>> $rawFiles */
    private function rejectLegacyConfig(array $rawFiles): void
    {
        foreach ($rawFiles as $raw) {
            if (array_key_exists('plesk', $raw)) {
                throw new InvalidConfigurationException(
                    'The legacy top-level "plesk" block is no longer supported. Configure servers instead, e.g.: '
                    .'servers: [{host: mail.company.com, secret_key: <key>}]',
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rawFiles
     * @param array<int, array<string, mixed>> $servers
     *
     * @return array<string, array<string, mixed>> host => raw per-server section
     */
    private function collectServerSections(array $rawFiles, array $servers): array
    {
        $hosts = array_column($servers, 'host');
        $sections = [];
        foreach ($rawFiles as $raw) {
            foreach ($raw as $key => $value) {
                if (in_array($key, self::SCHEMA_KEYS, true)) {
                    continue;
                }
                if (!is_array($value) || !in_array($key, $hosts, true)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Unknown top-level configuration key "%s". Top-level sections must name a configured server (configured servers: %s).',
                        $key,
                        implode(', ', $hosts),
                    ));
                }
                // Higher-precedence files (earlier in discovery order) win.
                $sections[$key] ??= $value;
            }
        }

        return $sections;
    }

    /** @param array<int, array<string, mixed>> $servers */
    private function resolveServerIndex(array $servers, ?string $serverOption): int
    {
        $selection = $serverOption;
        if (null === $selection || '' === $selection) {
            $env = getenv('PLEAD_SERVER');
            $selection = false === $env || '' === $env ? null : $env;
        }

        $hosts = array_column($servers, 'host');
        if (null === $selection) {
            return 0;
        }

        if (ctype_digit($selection)) {
            $index = (int) $selection;
            if (array_key_exists($index, $servers)) {
                return $index;
            }

            throw new InvalidConfigurationException(sprintf(
                'Server index "%s" is out of range (configured servers: %s).',
                $selection,
                implode(', ', $hosts),
            ));
        }

        $index = array_search(strtolower($selection), array_map('strtolower', $hosts), true);
        if (false === $index) {
            throw new InvalidConfigurationException(sprintf(
                'Server "%s" is not configured (configured servers: %s).',
                $selection,
                implode(', ', $hosts),
            ));
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, mixed>
     */
    private function applyAuthOverrides(array $server): array
    {
        foreach (['secret_key', 'login', 'password'] as $key) {
            $env = getenv('PLEAD_PLESK_'.strtoupper($key));
            if (false !== $env && '' !== $env) {
                $server[$key] = $env;
            }
        }

        return $server;
    }

    /**
     * General 'mail' config overlaid with the selected server's section:
     * defaults merge per key, groups merge by address with the per-server
     * entry winning.
     *
     * @param array<string, mixed> $generalMail
     * @param array<string, mixed> $serverSection
     *
     * @return array<string, mixed>
     */
    private function mergeMail(array $generalMail, array $serverSection): array
    {
        $serverMail = [];
        if (isset($serverSection['mail'])) {
            $serverMail = new Processor()
                ->processConfiguration(new MailConfiguration(), [$serverSection['mail']])
            ;
        }

        $defaults = array_merge($generalMail['defaults'] ?? [], $serverMail['defaults'] ?? []);
        $defaults['quota'] ??= null;
        $defaults['antivirus'] ??= 'off';

        $groups = [];
        foreach ($generalMail['group'] ?? [] as $entry) {
            $groups[$this->groupKey($entry)] = $entry;
        }
        foreach ($serverMail['group'] ?? [] as $entry) {
            $groups[$this->groupKey($entry)] = $entry;
        }

        return [
            'defaults' => $defaults,
            'group' => array_values($groups),
        ];
    }

    /** @param array<string, mixed> $entry */
    private function groupKey(array $entry): string
    {
        $address = (string) $entry['address'];
        $domain = null !== ($entry['domain'] ?? null)
            ? (string) $entry['domain']
            : (str_contains($address, '@') ? strtolower(substr($address, strpos($address, '@') + 1)) : '');

        return strtolower($address).'@'.strtolower($domain);
    }

    /** @return string[] */
    private function candidates(?string $explicitPath): array
    {
        return null !== $explicitPath ? [$explicitPath] : $this->paths->configPaths();
    }

    /** @return array<string, mixed> */
    private function parse(string $file): array
    {
        $content = file_get_contents($file);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Unable to read config file: %s', $file));
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        try {
            return match ($extension) {
                'yaml', 'yml' => Yaml::parse($content),
                'json' => json_decode($content, true, 512, JSON_THROW_ON_ERROR),
                default => throw new \RuntimeException(sprintf('Unsupported config file extension: %s', $file)),
            };
        } catch (\JsonException|ParseException $e) {
            throw new \RuntimeException(sprintf('Failed to parse config file %s: %s', $file, $e->getMessage()), 0, $e);
        }
    }
}
