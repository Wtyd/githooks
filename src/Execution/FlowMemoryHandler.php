<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\Memory\MemoryEvaluator;
use Wtyd\GitHooks\Execution\Memory\MemorySample;
use Wtyd\GitHooks\Execution\Memory\MemorySampler;
use Wtyd\GitHooks\Execution\Memory\MemorySamplerFactory;
use Wtyd\GitHooks\Execution\Memory\MemoryThresholdEvaluator;
use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Wraps the memory-budget runtime concerns for a single flow execution:
 * RSS sampler, evaluator, kill decision, per-job result enrichment and
 * stats attach. Lives one execution; the FlowExecutor instantiates it at
 * the start of execute() and discards it at the end.
 *
 * Lifted out of FlowExecutor to keep that class under PHPMD's method-count
 * threshold and to put every memory concern in one place.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Touches every piece of the memory feature.
 */
final class FlowMemoryHandler
{
    private OptionsConfiguration $options;

    private bool $disabled;

    private ?MemoryEvaluator $evaluator = null;

    private ?MemorySampler $sampler = null;

    private float $flowStartTime;

    /** @var array<string, int> jobName => threads from ThreadBudgetAllocator */
    private array $threadAllocations;

    /**
     * @param array<string, int> $threadAllocations
     */
    public function __construct(
        OptionsConfiguration $options,
        bool $disabled,
        float $flowStartTime,
        array $threadAllocations
    ) {
        $this->options = $options;
        $this->disabled = $disabled;
        $this->flowStartTime = $flowStartTime;
        $this->threadAllocations = $threadAllocations;
    }

    /**
     * Decide whether to instantiate sampler+evaluator. Returns true when at
     * least one of REQ-022 conditions applies. Emits a one-time warning on
     * stderr when sampling is unavailable but a budget/threshold was
     * declared (REQ-038).
     *
     * @param JobAbstract[] $jobs
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each REQ-022 condition is one explicit branch.
     */
    public function setup(array $jobs): bool
    {
        $hasJobThreshold = false;
        foreach ($jobs as $job) {
            if ($job->getMemoryReserve() !== null || $job->getMemoryThreshold() !== null) {
                $hasJobThreshold = true;
                break;
            }
        }
        $budget = $this->disabled ? null : $this->options->getMemoryBudget();
        $statsRequested = $this->options->isStats();
        $thresholdsRequested = $budget !== null || $hasJobThreshold;

        if (!$thresholdsRequested && !$statsRequested) {
            return false;
        }

        $sampler = (new MemorySamplerFactory())->create();
        if (!$sampler->isAvailable() && $thresholdsRequested) {
            fwrite(
                STDERR,
                '⚠ Memory budget disabled: ' . $sampler->getUnavailableReason() . PHP_EOL
            );
        }

        $this->sampler = $sampler;
        $this->evaluator = new MemoryEvaluator(
            $sampler->isAvailable(),
            $this->options->getProcesses()
        );
        return true;
    }

    public function isActive(): bool
    {
        return $this->evaluator !== null;
    }

    /**
     * @param array<string, array{process: \Symfony\Component\Process\Process, job: JobAbstract, start: float}> $running
     */
    public function tick(array $running): void
    {
        if ($this->evaluator === null) {
            return;
        }

        $atSecond = microtime(true) - $this->flowStartTime;

        if ($this->sampler !== null && $this->sampler->isAvailable()) {
            $pids = [];
            foreach ($running as $name => $entry) {
                $pid = $entry['process']->getPid();
                if ($pid !== null) {
                    $pids[$name] = $pid;
                }
            }
            $rssMap = $this->sampler->sample($pids);
            $this->evaluator->recordMemorySample(new MemorySample($atSecond, $rssMap));
        }

        $coresInUse = 0;
        foreach (array_keys($running) as $name) {
            $coresInUse += $this->threadAllocations[$name] ?? 1;
        }
        $this->evaluator->recordCoresSample($atSecond, $coresInUse, array_keys($running));
    }

    public function shouldKill(): bool
    {
        if ($this->disabled || $this->evaluator === null) {
            return false;
        }
        return $this->evaluator->isKillRequested($this->options->getMemoryBudget());
    }

    /**
     * @param JobResult[]   $results
     * @param JobAbstract[] $jobs
     * @return JobResult[]
     */
    public function enrichResults(array $results, array $jobs): array
    {
        if ($this->evaluator === null) {
            return $results;
        }

        $jobsByName = [];
        foreach ($jobs as $job) {
            $jobsByName[$job->getName()] = $job;
        }

        $enriched = [];
        foreach ($results as $result) {
            $enriched[] = $this->enrichSingle($result, $jobsByName);
        }
        return $enriched;
    }

    /**
     * @param array<string, JobAbstract> $jobsByName
     */
    private function enrichSingle(JobResult $result, array $jobsByName): JobResult
    {
        $name = $result->getJobName();
        $job = $jobsByName[$name] ?? null;

        $peak = $this->evaluator !== null ? $this->evaluator->getJobPeak($name) : null;
        if ($peak !== null) {
            $result = $result->withMemoryPeak($peak);
        }

        if ($job === null) {
            return $result;
        }

        $reserve = $job->getMemoryReserve();
        if ($reserve !== null) {
            $result = $result->withMemoryReserved($reserve);
        }

        $threshold = $job->getMemoryThreshold();
        if (!$this->disabled && $threshold !== null && $peak !== null) {
            $eval = MemoryThresholdEvaluator::evaluate($peak, $threshold);
            $result = $result->withMemoryThreshold(
                $eval['state'],
                $eval['reason'],
                $threshold->getWarnAbove(),
                $threshold->getFailAbove()
            );
        }

        return $result;
    }

    public function attachStats(FlowResult $flowResult): void
    {
        if ($this->evaluator === null) {
            return;
        }

        if (!$this->disabled) {
            $flowResult->setMemoryBudgetState(
                $this->evaluator->buildBudgetState($this->options->getMemoryBudget())
            );
        }

        if ($this->options->isStats()) {
            $flowResult->setMemoryStats($this->evaluator->buildStats());
        }
    }
}
