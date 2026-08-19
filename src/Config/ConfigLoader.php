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
    public function __construct(private readonly PathProviderInterface $paths)
    {
    }

    /**
     * @return array<string, mixed> resolved, validated configuration
     *
     * @throws InvalidConfigurationException when required keys are missing
     */
    public function load(?string $explicitPath = null): array
    {
        $candidates = null !== $explicitPath ? [$explicitPath] : $this->paths->configPaths();

        $raw = [];
        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $raw[] = $this->parse($candidate);
        }

        $configs = array_reverse($raw);
        $configs[] = $this->environmentOverrides();

        $processed = (new Processor())->processConfiguration(new PleadConfiguration(), $configs);

        if (!isset($processed['plesk']['host'])) {
            throw new InvalidConfigurationException(
                'plesk.host is required. Set it via `plead config:set plesk.host ...` or the PLEAD_PLESK_HOST environment variable.',
            );
        }

        $hasSecretKey = !empty($processed['plesk']['secret_key']);
        $hasCredentials = !empty($processed['plesk']['login']) && !empty($processed['plesk']['password']);
        if (!$hasSecretKey && !$hasCredentials) {
            throw new InvalidConfigurationException(
                'Either plesk.secret_key or plesk.login with plesk.password is required. '
                . 'Set it via `plead config:set ...` or the PLEAD_PLESK_SECRET_KEY / PLEAD_PLESK_LOGIN + PLEAD_PLESK_PASSWORD environment variables.',
            );
        }

        return $processed;
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
        } catch (ParseException | \JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to parse config file %s: %s', $file, $e->getMessage()), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function environmentOverrides(): array
    {
        $overrides = [];
        if (false !== ($host = getenv('PLEAD_PLESK_HOST')) && '' !== $host) {
            $overrides['plesk']['host'] = $host;
        }
        if (false !== ($secretKey = getenv('PLEAD_PLESK_SECRET_KEY')) && '' !== $secretKey) {
            $overrides['plesk']['secret_key'] = $secretKey;
        }
        if (false !== ($login = getenv('PLEAD_PLESK_LOGIN')) && '' !== $login) {
            $overrides['plesk']['login'] = $login;
        }
        if (false !== ($password = getenv('PLEAD_PLESK_PASSWORD')) && '' !== $password) {
            $overrides['plesk']['password'] = $password;
        }

        return $overrides;
    }
}
