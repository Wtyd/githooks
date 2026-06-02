<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wtyd\GitHooks\Execution\Diagnostics;
use Wtyd\GitHooks\Output\Diagnostics\DiagnosticsRenderer;

/**
 * Emits the runtime diagnostics block (FEAT-14) just before the `Settings:`
 * header, when applicable. Parallel to {@see ConditionsHeaderEmitter}; it does
 * not touch it (separate buffer).
 *
 * Emission decision (factors table):
 *   - In CI (the snapshot carries a CI name)      → multiline, auto-on.
 *   - Local with `--diag`                          → compact (single line).
 *   - Local without `--diag`                       → nothing (zero noise).
 * Channel (same rule as the header, BUG-5): text → stdout; clean-stdout formats
 * (structured + claude-code) → stderr only with `--show-progress`, else suppressed.
 *
 * The block is flushed immediately so a later hang still leaves it in the log
 * (AC-007): it is the signal that distinguishes "githooks never started" from
 * "started but blocked".
 */
class DiagnosticsHeaderEmitter
{
    private DiagnosticsRenderer $renderer;

    public function __construct(?DiagnosticsRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new DiagnosticsRenderer();
    }

    public function emit(
        Diagnostics $diagnostics,
        string $timestamp,
        bool $diagFlag,
        HeaderOptions $options,
        OutputInterface $output
    ): void {
        $inCi = $diagnostics->getCi() !== null;
        if (!$inCi && !$diagFlag) {
            return;
        }

        $sink = $this->resolveSink($options, $output);
        if ($sink === null) {
            return;
        }

        if ($inCi) {
            foreach ($this->renderer->renderMultiline($diagnostics, $timestamp) as $line) {
                $sink->writeln($line);
            }
        } else {
            $sink->writeln($this->renderer->renderCompact($diagnostics, $timestamp));
        }

        $this->flush();
    }

    /**
     * Same channel rule as {@see ConditionsHeaderEmitter::resolveSink()}:
     * text → the OutputInterface (stdout); clean-stdout formats → stderr via
     * getErrorStyle() only with --show-progress on a SymfonyStyle, else null.
     */
    private function resolveSink(HeaderOptions $options, OutputInterface $output): ?OutputInterface
    {
        if (!OutputFormats::hasCleanStdout($options->format)) {
            return $output;
        }
        if (!$options->showProgress) {
            return null;
        }
        if ($output instanceof SymfonyStyle) {
            return $output->getErrorStyle();
        }
        return null;
    }

    /**
     * Force a flush so the block survives a later hang (AC-007). Protected so
     * tests can no-op it. Operates below PHP output buffering, so it does not
     * disturb the test harness's ob_start capture.
     */
    protected function flush(): void
    {
        if (function_exists('flush')) {
            flush();
        }
    }
}
