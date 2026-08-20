<?php

declare(strict_types=1);

namespace App\Gateway;

use Psr\Log\LoggerInterface;

/**
 * Plesk REST API gateway (https://<host>:8443/api/v2). Used for surfaces the
 * XML API does not cover - primarily the generic CLI execution gate
 * (/cli/{id}/call), which runs `plesk <id> ...` server-side and covers
 * extension CLIs (e.g. sslit via the 'extension' id + --call) that do not
 * implement the XML ApiRpc hook.
 *
 * Auth: X-API-Key header with the same secret key as the XML API
 * (live-verified). Dry-run short-circuits every mutation without HTTP.
 */
class PleskRestGateway
{
    /** @var callable(string $method, string $url, array<string, string> $headers, ?string $body): array{0: int, 1: string} */
    private $transport;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $protocol,
        private readonly string $secretKey,
        private readonly bool $dryRun,
        private readonly LoggerInterface $logger,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? fn (string $method, string $url, array $headers, ?string $body): array => $this->curl($method, $url, $headers, $body);
    }

    /** @return string[] available CLI command ids (GET /cli/commands) */
    public function cliCommands(): array
    {
        [$status, $body] = $this->request('GET', '/cli/commands');
        $data = $this->decode($status, $body, '/cli/commands');

        return array_map('strval', (array) $data);
    }

    /**
     * Allowed commands/options of one CLI command (GET /cli/{id}/ref).
     *
     * @return array<string, mixed>
     */
    public function cliRef(string $id): array
    {
        [$status, $body] = $this->request('GET', '/cli/' . rawurlencode($id) . '/ref');
        $data = $this->decode($status, $body, '/cli/' . $id . '/ref');

        return is_array($data) ? $data : [];
    }

    /**
     * Execute a CLI command server-side (POST /cli/{id}/call).
     *
     * @param string[] $params command arguments, e.g. ['--call', 'sslit', '--help']
     *
     * @return array{code: int, stdout: string, stderr: string}
     *
     * @throws \RuntimeException on HTTP 422 (non-zero exit with fail_on_error)
     */
    public function cliCall(string $id, array $params, bool $failOnError = true): array
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would execute CLI command {id} with params {params}', [
                'id' => $id,
                'params' => implode(' ', $params),
            ]);

            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }

        [$status, $body] = $this->request('POST', '/cli/' . rawurlencode($id) . '/call', [
            'params' => $params,
            'fail_on_error' => $failOnError,
        ]);

        $data = $this->decode($status, $body, '/cli/' . $id . '/call');
        if (!is_array($data) || !array_key_exists('code', $data)) {
            throw new \RuntimeException(sprintf('Unexpected response from /cli/%s/call.', $id));
        }

        $result = [
            'code' => (int) $data['code'],
            'stdout' => (string) ($data['stdout'] ?? ''),
            'stderr' => (string) ($data['stderr'] ?? ''),
        ];

        if ($failOnError && 0 !== $result['code']) {
            throw new \RuntimeException(sprintf(
                'CLI command %s exited with code %d%s',
                $id,
                $result['code'],
                '' !== $result['stderr'] ? ': ' . $result['stderr'] : '',
            ));
        }

        $this->logger->info('Executed CLI command {id}: {stdout}', [
            'id' => $id,
            'stdout' => trim($result['stdout']),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $url = sprintf('%s://%s:%d/api/v2%s', $this->protocol, $this->host, $this->port, $path);
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->secretKey,
        ];
        $body = null;
        if (null !== $jsonBody) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->logger->debug('REST {method} {url}', ['method' => $method, 'url' => $url]);

        return ($this->transport)($method, $url, $headers, $body);
    }

    /** @return mixed decoded JSON body */
    private function decode(int $status, string $body, string $context): mixed
    {
        $data = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(sprintf('Invalid JSON response from %s: %s', $context, substr($body, 0, 200)));
        }

        if ($status >= 400) {
            $message = $data['stderr'] ?? $data['message'] ?? $data['error'] ?? substr($body, 0, 200);
            throw new \RuntimeException(sprintf('%s failed (HTTP %d): %s', $context, $status, is_string($message) ? $message : json_encode($message)));
        }

        return $data;
    }

    /** @param array<string, string> $headers */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        if (null !== $body) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, is_string($response) ? $response : ''];
    }
}
