<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Execution\InputFilesResolver;

/**
 * Shared logic for commands that accept --files / --files-from / --exclude-pattern.
 *
 * Hosting the wiring here avoids the duplication phpcpd would otherwise flag
 * between FlowCommand and JobCommand: invoking the resolver, surfacing
 * warnings (BOM, invalid paths, mixed flags), and (optionally) printing the
 * "Mode: files (N input files…)" header in text format.
 *
 * @property InputFilesResolver $inputFilesResolver  Provided by the consumer
 *           command via constructor injection.
 */
trait ResolvesInputFiles
{
    /**
     * Returns null when none of the input-files flags are present. Otherwise
     * returns the resolved object after emitting any informational warnings.
     * Throws InputFilesException for fatal spec conditions; the caller catches
     * it via GitHooksExceptionInterface.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Mirrors spec branches.
     */
    private function resolveInputFilesFlags(bool $printModeHeader = false): ?InputFilesResolution
    {
        $files          = $this->option('files');
        $filesFrom      = $this->option('files-from');
        $excludePattern = $this->option('exclude-pattern');

        if (
            ($files === null || $files === '')
            && ($filesFrom === null || $filesFrom === '')
            && ($excludePattern === null || $excludePattern === '')
        ) {
            return null;
        }

        $resolution = $this->inputFilesResolver->resolve(
            is_string($files) ? $files : null,
            is_string($filesFrom) ? $filesFrom : null,
            is_string($excludePattern) ? $excludePattern : null,
            (string) getcwd()
        );

        foreach ($resolution->getInvalid() as $invalid) {
            $this->warn("file '$invalid' does not exist, skipping");
        }
        if ($resolution->isBomDetected()) {
            $this->warn('--files-from: UTF-8 BOM detected and stripped');
        }

        if ($this->option('fast') || $this->option('fast-branch')) {
            $other   = $this->option('fast') ? '--fast' : '--fast-branch';
            $primary = is_string($files) && $files !== '' ? '--files' : '--files-from';
            $this->warn("$primary takes precedence over $other ($other ignored)");
        }

        if ($printModeHeader && (strval($this->option('format')) === '' || $this->option('format') === null)) {
            $totalValid   = $resolution->getTotalValid();
            $totalInvalid = count($resolution->getInvalid());
            $suffix       = $totalInvalid > 0 ? ", $totalInvalid invalid skipped" : '';
            $unit         = $totalValid === 1 ? 'file' : 'files';
            $this->line("  Mode: files ($totalValid input $unit$suffix)");
        }

        return $resolution;
    }
}
