<?php

declare(strict_types=1);

namespace App\Gateway;

final class PleskEndpoint
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $protocol,
    ) {}

    /**
     * Parse a host value that may carry a protocol and/or port, e.g.
     * "mail.company.com", "https://mail.company.com", "http://mail.company.com:8080".
     */
    public static function fromConfig(string $value): self
    {
        $original = $value;
        $protocol = 'https';
        if (1 === preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $value, $matches)) {
            $protocol = strtolower($matches[1]);
            $value = substr($value, strlen($matches[0]));
        }

        $port = 8443;
        if (1 === preg_match('#^(.+):(\d+)$#', $value, $matches)) {
            $value = $matches[1];
            $port = (int) $matches[2];
        }

        if ('' === $value) {
            throw new \InvalidArgumentException(sprintf('Invalid Plesk host value: "%s"', $original));
        }

        return new self($value, $port, $protocol);
    }
}
