<?php

declare(strict_types=1);

namespace App\Template;

use Symfony\Component\Process\Process;

/**
 * Converts MJML markup to HTML via the `mjml` CLI binary. The binary is
 * looked up on PATH (or via the MJML_BINARY_PATH environment variable) at
 * runtime - unlike spatie's node-module import this also works inside the
 * phar and in the container image.
 */
final class MjmlConverter
{
    /**
     * @param string $binary explicit binary path; '' = MJML_BINARY_PATH env, then 'mjml' on PATH
     */
    public function __construct(private readonly string $binary = '') {}

    public function convert(string $mjml): string
    {
        $binary = '' !== $this->binary ? $this->binary : (getenv('MJML_BINARY_PATH') ?: 'mjml');

        $process = new Process([$binary, '-i', '-s', '--noStdoutFileComment']);
        $process->setInput($mjml);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'MJML conversion failed (binary "%s"): %s',
                $binary,
                trim($process->getErrorOutput() ?: $process->getOutput()),
            ));
        }

        return $process->getOutput();
    }
}
