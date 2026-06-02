<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Input DTO for {@see JobRunner::prepare()} and {@see JobRunner::run()}. Built
 * by `JobCommand::handle()` after the CLI flags have been read and the
 * per-concern resolvers (`Resolves*Flag`) have produced their structs. All
 * fields are pre-resolved: the Runner does no I/O nor argument parsing itself.
 *
 * Public properties (not `readonly`) by PHP 7.4 compatibility (the tier
 * `builds/php7.4/` ships on PHP 7.4/8.0). Treat as immutable at the consumer
 * boundary — do not mutate after construction.
 */
class JobRunRequest
{
    public string $jobName;

    public string $configFile;

    public string $cliExtraArgs;

    public ?InputFilesResolution $inputFiles;

    public ?string $invocationMode;

    public ?int $timeBudgetWarn;

    public ?int $timeBudgetFail;

    public bool $timeBudgetDisabled;

    public ?int $memoryWarnAbove;

    public ?int $memoryFailAbove;

    public bool $memoryBudgetDisabled;

    public ?bool $statsFlag;

    public ?bool $cliFailFast;

    public bool $dryRun;

    /** FEAT-16: resolved commit-message file path for a `commit-msg` job (null otherwise). */
    public ?string $commitMessageFile;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DTO mirroring 14 pre-resolved CLI inputs;
     *   merging into sub-structs would obscure the JobCommand → JobRunner contract.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The `disabled` / `dryRun` booleans are
     *   configuration toggles pre-resolved by the Command, not branching flags inside this DTO.
     */
    public function __construct(
        string $jobName,
        string $configFile,
        string $cliExtraArgs,
        ?InputFilesResolution $inputFiles,
        ?string $invocationMode,
        ?int $timeBudgetWarn,
        ?int $timeBudgetFail,
        bool $timeBudgetDisabled,
        ?int $memoryWarnAbove,
        ?int $memoryFailAbove,
        bool $memoryBudgetDisabled,
        ?bool $statsFlag,
        ?bool $cliFailFast,
        bool $dryRun = false,
        ?string $commitMessageFile = null
    ) {
        $this->jobName = $jobName;
        $this->configFile = $configFile;
        $this->cliExtraArgs = $cliExtraArgs;
        $this->inputFiles = $inputFiles;
        $this->invocationMode = $invocationMode;
        $this->timeBudgetWarn = $timeBudgetWarn;
        $this->timeBudgetFail = $timeBudgetFail;
        $this->timeBudgetDisabled = $timeBudgetDisabled;
        $this->memoryWarnAbove = $memoryWarnAbove;
        $this->memoryFailAbove = $memoryFailAbove;
        $this->memoryBudgetDisabled = $memoryBudgetDisabled;
        $this->statsFlag = $statsFlag;
        $this->cliFailFast = $cliFailFast;
        $this->dryRun = $dryRun;
        $this->commitMessageFile = $commitMessageFile;
    }
}
