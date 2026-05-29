<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Input DTO for {@see FlowRunner::prepare()} and {@see FlowRunner::run()}.
 * Built by `FlowCommand::handle()` after the CLI flags have been read and
 * the per-concern resolvers (`Resolves*Flag`) have produced their structs.
 * All fields are pre-resolved: the Runner does no I/O nor argument parsing
 * itself.
 *
 * Public properties not readonly by PHP 7.4 compatibility — treat as
 * immutable at the consumer boundary.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) DTO surfaces the 18 pre-resolved CLI inputs
 *   1:1; merging into sub-structs would obscure the FlowCommand → FlowRunner contract.
 */
class FlowRunRequest
{
    public string $flowName;

    public string $configFile;

    public ?bool $cliFailFast;

    public ?int $cliProcesses;

    /** @var string[] */
    public array $excludeJobs;

    /** @var string[] */
    public array $onlyJobs;

    public ?InputFilesResolution $inputFiles;

    public ?string $invocationMode;

    public ?int $timeBudgetWarn;

    public ?int $timeBudgetFail;

    public bool $timeBudgetDisabled;

    public ?int $memoryWarnAbove;

    public ?int $memoryFailAbove;

    public bool $memoryBudgetDisabled;

    public ?string $cliAllocator;

    public ?bool $cliStats;

    public ?string $cliBranch;

    public bool $dryRun;

    public bool $monitor;

    /**
     * @param string[] $excludeJobs
     * @param string[] $onlyJobs
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DTO mirroring 18 pre-resolved CLI inputs;
     *   merging into sub-structs would obscure the FlowCommand → FlowRunner contract.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The `disabled` / `dryRun` / `monitor`
     *   booleans are configuration toggles pre-resolved by the Command.
     */
    public function __construct(
        string $flowName,
        string $configFile,
        ?bool $cliFailFast,
        ?int $cliProcesses,
        array $excludeJobs,
        array $onlyJobs,
        ?InputFilesResolution $inputFiles,
        ?string $invocationMode,
        ?int $timeBudgetWarn,
        ?int $timeBudgetFail,
        bool $timeBudgetDisabled,
        ?int $memoryWarnAbove,
        ?int $memoryFailAbove,
        bool $memoryBudgetDisabled,
        ?string $cliAllocator,
        ?bool $cliStats,
        ?string $cliBranch,
        bool $dryRun,
        bool $monitor
    ) {
        $this->flowName = $flowName;
        $this->configFile = $configFile;
        $this->cliFailFast = $cliFailFast;
        $this->cliProcesses = $cliProcesses;
        $this->excludeJobs = $excludeJobs;
        $this->onlyJobs = $onlyJobs;
        $this->inputFiles = $inputFiles;
        $this->invocationMode = $invocationMode;
        $this->timeBudgetWarn = $timeBudgetWarn;
        $this->timeBudgetFail = $timeBudgetFail;
        $this->timeBudgetDisabled = $timeBudgetDisabled;
        $this->memoryWarnAbove = $memoryWarnAbove;
        $this->memoryFailAbove = $memoryFailAbove;
        $this->memoryBudgetDisabled = $memoryBudgetDisabled;
        $this->cliAllocator = $cliAllocator;
        $this->cliStats = $cliStats;
        $this->cliBranch = $cliBranch;
        $this->dryRun = $dryRun;
        $this->monitor = $monitor;
    }
}
