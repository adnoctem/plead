<?php

declare(strict_types=1);

namespace App\Template\DevServer;

/**
 * Bootstrap for PHP's built-in server. The template:serve command writes a
 * temporary copy of this class' invocation and passes the state file path via
 * PLEAD_TEMPLATE_SERVE_STATE.
 */
final class Router
{
    public function __construct(private readonly ?string $stateFile = null) {}

    public function handle(): void
    {
        $stateFile = $this->stateFile ?? (string) getenv('PLEAD_TEMPLATE_SERVE_STATE');
        $state = [];

        if ('' === $stateFile || !is_file($stateFile)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Template studio state file is missing.';

            return;
        }

        try {
            $decoded = json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Template studio state is invalid: '.$e->getMessage();

            return;
        }

        if (!is_array($decoded)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Template studio state must be a JSON object.';

            return;
        }

        new Server($decoded)->handle($_SERVER, $_GET);
    }
}
