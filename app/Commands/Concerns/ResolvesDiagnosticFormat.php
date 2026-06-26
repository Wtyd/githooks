<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Output\OutputFormats;

/**
 * Resolve the `--format=` option for the diagnostic commands (`conf:check`,
 * `status`, `system:info`), which expose only `text` and `json`.
 *
 * Mirrors the execution commands' behaviour (FlowResultRenderer::applyFormat):
 * an unknown format is not a hard error — it emits a warning to stderr and
 * falls back to `text`, so stdout stays usable and the exit code is unaffected.
 *
 * Consuming classes must also use the {@see EmitsStderr} trait, which keeps the
 * warning off stdout (so it never pollutes the JSON payload) and silent inside
 * test buffers.
 */
trait ResolvesDiagnosticFormat
{
    /**
     * @return string `'text'` or `'json'`
     */
    protected function resolveDiagnosticFormat(): string
    {
        $format = strval($this->option('format'));

        if ($format === '') {
            return 'text';
        }

        if (!in_array($format, OutputFormats::DIAGNOSTIC, true)) {
            $this->emitStderr(
                "Unknown format '$format'. Using text output. Valid formats: "
                . implode(', ', OutputFormats::DIAGNOSTIC) . '.'
            );
            return 'text';
        }

        return $format;
    }
}
