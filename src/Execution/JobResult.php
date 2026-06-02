<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

/**
 * @SuppressWarnings(PHPMD.TooManyFields) Immutable value object — every field describes one
 *   facet of a single job's outcome. Aggregating into sub-VOs would only spread the same data
 *   across more types.
 */
class JobResult
{
    public const THRESHOLD_NONE = 0;

    public const THRESHOLD_WARNED = 1;

    public const THRESHOLD_FAILED = 2;

    public const THRESHOLD_REASON_WARN = 'exceeded warn-after';

    public const THRESHOLD_REASON_FAIL = 'exceeded fail-after';

    public const MEMORY_THRESHOLD_NONE = 0;

    public const MEMORY_THRESHOLD_WARNED = 1;

    public const MEMORY_THRESHOLD_FAILED = 2;

    public const MEMORY_REASON_WARN = 'exceeded warn-above';

    public const MEMORY_REASON_FAIL = 'exceeded fail-above';

    private string $jobName;

    private bool $success;

    private string $output;

    private string $executionTime;

    private bool $fixApplied;

    private ?string $command;

    private string $type;

    private ?int $exitCode;

    /** @var string[] */
    private array $paths;

    private bool $skipped;

    private ?string $skipReason;

    private ?string $stdout;

    private ?InputFilesPerJob $inputFiles;

    private float $durationSeconds;

    private int $thresholdState;

    private ?string $thresholdReason;

    private ?int $configuredWarnAfter;

    private ?int $configuredFailAfter;

    /** @var ?string ISO-8601 wall-clock when the job started (FEAT-14); null for skipped jobs. */
    private ?string $startedAt;

    /** @var ?string ISO-8601 wall-clock when the job ended (FEAT-14); null for skipped jobs. */
    private ?string $endedAt;

    private ?int $memoryPeak = null;

    private int $memoryThresholdState = self::MEMORY_THRESHOLD_NONE;

    private ?string $memoryThresholdReason = null;

    private ?int $configuredMemoryWarn = null;

    private ?int $configuredMemoryFail = null;

    private ?int $memoryReserved = null;

    private ?string $killedReason = null;

    /** @var string[] FEAT-3: job names this entry declared as `needs` */
    private array $needs = [];

    /**
     * @param string[] $paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Value object with backward-compatible defaults
     */
    public function __construct(
        string $jobName,
        bool $success,
        string $output,
        string $executionTime,
        bool $fixApplied = false,
        ?string $command = null,
        string $type = '',
        ?int $exitCode = null,
        array $paths = [],
        bool $skipped = false,
        ?string $skipReason = null,
        ?string $stdout = null,
        ?InputFilesPerJob $inputFiles = null,
        float $durationSeconds = 0.0,
        int $thresholdState = self::THRESHOLD_NONE,
        ?string $thresholdReason = null,
        ?int $configuredWarnAfter = null,
        ?int $configuredFailAfter = null,
        ?string $startedAt = null,
        ?string $endedAt = null
    ) {
        $this->jobName = $jobName;
        $this->success = $success;
        $this->output = $output;
        $this->executionTime = $executionTime;
        $this->fixApplied = $fixApplied;
        $this->command = $command;
        $this->type = $type;
        $this->exitCode = $exitCode;
        $this->paths = $paths;
        $this->skipped = $skipped;
        $this->skipReason = $skipReason;
        $this->stdout = $stdout;
        $this->inputFiles = $inputFiles;
        $this->durationSeconds = $durationSeconds;
        $this->thresholdState = $thresholdState;
        $this->thresholdReason = $thresholdReason;
        $this->configuredWarnAfter = $configuredWarnAfter;
        $this->configuredFailAfter = $configuredFailAfter;
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
    }

    /**
     * Create a result for a job that was skipped (not executed).
     *
     * @param string[] $paths
     */
    public static function skipped(string $jobName, string $type, string $reason, array $paths = []): self
    {
        return new self($jobName, true, '', '0ms', false, null, $type, null, $paths, true, $reason);
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getExecutionTime(): string
    {
        return $this->executionTime;
    }

    /** ISO-8601 wall-clock when the job started, or null for skipped jobs (FEAT-14). */
    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    /** ISO-8601 wall-clock when the job ended, or null for skipped jobs (FEAT-14). */
    public function getEndedAt(): ?string
    {
        return $this->endedAt;
    }

    public function isFixApplied(): bool
    {
        return $this->fixApplied;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /** @return string[] */
    public function getPaths(): array
    {
        return $this->paths;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    /** Raw stdout from the process (separate from combined output). */
    public function getStdout(): ?string
    {
        return $this->stdout;
    }

    public function getInputFiles(): ?InputFilesPerJob
    {
        return $this->inputFiles;
    }

    /**
     * Return a new JobResult with the given inputFiles slice attached.
     */
    public function withInputFiles(?InputFilesPerJob $inputFiles): self
    {
        $clone = clone $this;
        $clone->inputFiles = $inputFiles;
        return $clone;
    }

    /**
     * FEAT-3: declared dependencies of the flow entry (propagated from JobRef).
     * Empty array when no `needs` were declared. Surfaces in JSON v2 only when
     * non-empty (D5).
     *
     * @return string[]
     */
    public function getNeeds(): array
    {
        return $this->needs;
    }

    /**
     * @param string[] $needs
     */
    public function withNeeds(array $needs): self
    {
        $clone = clone $this;
        $clone->needs = $needs;
        return $clone;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function getThresholdState(): int
    {
        return $this->thresholdState;
    }

    public function getThresholdReason(): ?string
    {
        return $this->thresholdReason;
    }

    public function getConfiguredWarnAfter(): ?int
    {
        return $this->configuredWarnAfter;
    }

    public function getConfiguredFailAfter(): ?int
    {
        return $this->configuredFailAfter;
    }

    public function hasThreshold(): bool
    {
        return $this->configuredWarnAfter !== null || $this->configuredFailAfter !== null;
    }

    public function isThresholdWarned(): bool
    {
        return $this->thresholdState === self::THRESHOLD_WARNED;
    }

    public function isThresholdFailed(): bool
    {
        return $this->thresholdState === self::THRESHOLD_FAILED;
    }

    /**
     * Return a clone with threshold state/reason overridden. Used by FlowExecutor
     * to annotate KO-real jobs that also crossed a threshold (information only —
     * success/exitCode are not altered when the tool already failed).
     */
    public function withThreshold(int $state, ?string $reason): self
    {
        $clone = clone $this;
        $clone->thresholdState = $state;
        $clone->thresholdReason = $reason;
        return $clone;
    }

    public function getMemoryPeak(): ?int
    {
        return $this->memoryPeak;
    }

    public function withMemoryPeak(?int $peak): self
    {
        $clone = clone $this;
        $clone->memoryPeak = $peak;
        return $clone;
    }

    public function getMemoryThresholdState(): int
    {
        return $this->memoryThresholdState;
    }

    public function getMemoryThresholdReason(): ?string
    {
        return $this->memoryThresholdReason;
    }

    public function getConfiguredMemoryWarn(): ?int
    {
        return $this->configuredMemoryWarn;
    }

    public function getConfiguredMemoryFail(): ?int
    {
        return $this->configuredMemoryFail;
    }

    public function hasMemoryThreshold(): bool
    {
        return $this->configuredMemoryWarn !== null || $this->configuredMemoryFail !== null;
    }

    public function isMemoryWarned(): bool
    {
        return $this->memoryThresholdState === self::MEMORY_THRESHOLD_WARNED;
    }

    public function isMemoryFailed(): bool
    {
        return $this->memoryThresholdState === self::MEMORY_THRESHOLD_FAILED;
    }

    public function withMemoryThreshold(int $state, ?string $reason, ?int $warnAbove, ?int $failAbove): self
    {
        $clone = clone $this;
        $clone->memoryThresholdState = $state;
        $clone->memoryThresholdReason = $reason;
        $clone->configuredMemoryWarn = $warnAbove;
        $clone->configuredMemoryFail = $failAbove;
        return $clone;
    }

    /**
     * Flip success OK→KO because the per-job memory threshold crossed fail-above.
     * Mirrors the time-budget contract from FlowExecutor (RAT-006): the flip
     * applies only when the tool itself passed; pre-existing failures are
     * preserved by the caller.
     */
    public function withFailureByMemoryThreshold(): self
    {
        $clone = clone $this;
        $clone->success = false;
        return $clone;
    }

    public function getMemoryReserved(): ?int
    {
        return $this->memoryReserved;
    }

    public function withMemoryReserved(?int $reserved): self
    {
        $clone = clone $this;
        $clone->memoryReserved = $reserved;
        return $clone;
    }

    public function getKilledReason(): ?string
    {
        return $this->killedReason;
    }

    public function withKilled(string $reason): self
    {
        $clone = clone $this;
        $clone->success = false;
        $clone->killedReason = $reason;
        return $clone;
    }
}
