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
    /** Stats table sort modes (FEAT-4). */
    public const STATS_SORT_EXEC = 'exec';
    public const STATS_SORT_NAME = 'name';
    public const STATS_SORT_TYPE = 'type';
    public const STATS_SORTS = [self::STATS_SORT_EXEC, self::STATS_SORT_NAME, self::STATS_SORT_TYPE];

    public string $format;

    public ?string $outputPath;

    public bool $noReports;

    public bool $noCI;

    public bool $showProgress;

    /** @var array<string, string> CLI report targets: format ('json'|'junit'|'sarif'|'codeclimate') => path */
    public array $cliReports;

    /** Opt-in runtime diagnostics block in local runs (FEAT-14). Auto-on in CI regardless. */
    public bool $diag;

    /** Stats table sort mode (FEAT-4): one of STATS_SORTS. Default `exec` (completion order). */
    public string $statsSort;

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
        bool $diag = false,
        string $statsSort = self::STATS_SORT_EXEC
    ) {
        $this->format = $format;
        $this->outputPath = $outputPath;
        $this->noReports = $noReports;
        $this->noCI = $noCI;
        $this->showProgress = $showProgress;
        $this->cliReports = $cliReports;
        $this->diag = $diag;
        $this->statsSort = in_array($statsSort, self::STATS_SORTS, true) ? $statsSort : self::STATS_SORT_EXEC;
    }
}
