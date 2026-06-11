<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * Input DTO for {@see FlowsRunner::prepare()} and {@see FlowsRunner::run()}.
 * Multi-flow variant of {@see FlowRunRequest}: takes a list of flow names
 * (1+) instead of a single name. The Runner uses the array shape to decide
 * between the four invocation modes (single-flow degenerate, declarative
 * meta-flow, ad-hoc, mixed).
 *
 * Public properties not readonly by PHP 7.4 compatibility.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) DTO surfaces the 19 pre-resolved CLI inputs
 *   1:1; merging into sub-structs would obscure the FlowsCommand → FlowsRunner contract.
 */
class FlowsRunRequest
{
    /** @var string[] */
    public array $flowNames;

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

    /** FEAT-5: persist this run to .githooks/history/ regardless of config. */
    public bool $saveHistory;

    /**
     * @param string[] $flowNames
     * @param string[] $excludeJobs
     * @param string[] $onlyJobs
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DTO mirroring 19 pre-resolved CLI inputs.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Configuration toggles pre-resolved by the Command.
     */
    public function __construct(
        array $flowNames,
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
        bool $monitor,
        bool $saveHistory = false
    ) {
        $this->flowNames = $flowNames;
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
        $this->saveHistory = $saveHistory;
    }
}
