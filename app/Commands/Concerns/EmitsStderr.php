<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Route advisories, deprecation warnings and exception messages to the
 * console's STDERR stream while staying silent inside test buffers.
 *
 * Production: $this->output is an Illuminate\Console\OutputStyle wrapping a
 * Symfony\Component\Console\Output\ConsoleOutput; we ask it for its
 * dedicated stderr stream via getErrorOutput() so the message lands on
 * fd 2, respects --no-ansi, and stays out of the JSON/JUnit/SARIF payload
 * on stdout (BUG-5/12/14).
 *
 * Tests: Illuminate's $this->artisan() builds an OutputStyle around a
 * BufferedOutput which does not implement ConsoleOutputInterface. Routing
 * to the buffer would surface the message on stdout (back to square one);
 * dropping it keeps the phpunit run output clean. Tests that need to
 * assert on a stderr message must drive the command with a real
 * ConsoleOutput or a double that overrides emitStderr().
 */
trait EmitsStderr
{
    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) used by classes that consume the trait
     */
    protected function emitStderr(string $message): void
    {
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        }
    }
}
