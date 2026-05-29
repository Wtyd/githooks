<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Concerns;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle as SymfonyOutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared stderr-routing for the Phase 2 Runners (Job/Flow/Flows). All three
 * need the same two helpers when surfacing errors from inside run():
 *
 *   - emitError(): red `<error>…</error>` line, routed via getErrorStyle()
 *     when on a SymfonyStyle, else writeln directly.
 *   - emitStderr(): plain message routed to the console's stderr stream when
 *     available, or to writeln on duck-typed test outputs.
 *
 * Lives under src/Execution/Concerns/ because the Runners are pure
 * orchestration: no Command base class, no Laravel facade — just the
 * Symfony Console OutputInterface contract.
 */
trait EmitsRunnerStderr
{
    private function emitError(OutputInterface $output, string $message): void
    {
        if ($output instanceof SymfonyStyle) {
            $output->getErrorStyle()->writeln("<error>$message</error>");
            return;
        }
        $output->writeln("<error>$message</error>");
    }

    private function emitStderr(OutputInterface $output, string $message): void
    {
        if ($output instanceof SymfonyOutputStyle && method_exists($output, 'getOutput')) {
            $underlying = $output->getOutput();
            if ($underlying instanceof ConsoleOutputInterface) {
                $underlying->getErrorOutput()->writeln($message);
                return;
            }
        }
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
            return;
        }
        $output->writeln($message);
    }
}
