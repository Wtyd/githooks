<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Pre-resolved inputs for {@see FlowResultRenderer::applyFormat()} and
 * {@see FlowResultRenderer::renderFormattedResult()}. Built by the Command
 * (or Runner) from the parsed CLI flags. Public properties not readonly by
 * PHP 7.4 compatibility — treat as immutable at the boundary.
 */
class RenderOptions
{
    public string $format;

    public ?string $outputPath;

    public bool $noReports;

    public bool $noCI;

    public bool $showProgress;

    /** @var array<string, string> CLI report targets: format ('json'|'junit'|'sarif'|'codeclimate') => path */
    public array $cliReports;

    /** Opt-in runtime diagnostics block in local runs (FEAT-14). Auto-on in CI regardless. */
    public bool $diag;

    /**
     * @param array<string, string> $cliReports
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __construct(
        string $format,
        ?string $outputPath,
        bool $noReports,
        bool $noCI,
        bool $showProgress,
        array $cliReports,
        bool $diag = false
    ) {
        $this->format = $format;
        $this->outputPath = $outputPath;
        $this->noReports = $noReports;
        $this->noCI = $noCI;
        $this->showProgress = $showProgress;
        $this->cliReports = $cliReports;
        $this->diag = $diag;
    }
}
