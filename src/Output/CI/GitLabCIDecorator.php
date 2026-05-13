<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\CI;

use Closure;
use Wtyd\GitHooks\Output\OutputHandler;

/**
 * GitLab CI decorator.
 *
 * Emits each job as an *atomic* collapsible section: the section is opened,
 * filled and closed in a single synchronous call to `onJobSuccess` /
 * `onJobError` / `onJobSkipped`. This is the only way to keep sections
 * non-overlapping under parallel execution — GitLab's section_start /
 * section_end protocol does not support interleaved sections.
 *
 * Rules:
 *   - OK         → section[collapsed=true]   (folded by default).
 *   - KO         → section[collapsed=false]  (auto-expanded so the failure is visible).
 *   - SKIPPED    → section[collapsed=true].
 *   - inner.flush()  → suppressed: KO sections already carry the tool output;
 *                      letting the inner emit framed error blocks would print
 *                      them outside any section and duplicate the content.
 *
 * Per-job buffering uses ob_start / ob_get_clean around each delegated call.
 * Because PHP runs the OutputHandler methods on the main thread, each
 * start/clean pair brackets exactly one method invocation — interleaving
 * across jobs cannot corrupt the buffers.
 */
class GitLabCIDecorator extends CIOutputDecorator
{
    private int $sectionCounter = 0;

    /** @var array<string, string> jobName => buffered section body */
    private array $buffers = [];

    /** @var array<string, int> jobName => unix timestamp captured at onJobStart */
    private array $startTimes = [];

    /** @var Closure(): int */
    private $clock;

    /**
     * @param Closure(): int|null $clock Optional clock injection for deterministic tests.
     *                                   Defaults to `time()`. Same pattern as FlowExecutor::clockOverride.
     */
    public function __construct(OutputHandler $inner, ?Closure $clock = null)
    {
        parent::__construct($inner);
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    public function onJobStart(string $jobName): void
    {
        if (!isset($this->startTimes[$jobName])) {
            $this->startTimes[$jobName] = ($this->clock)();
        }
        $this->buffers[$jobName] = ($this->buffers[$jobName] ?? '') . $this->captureInner(function () use ($jobName) {
            $this->inner->onJobStart($jobName);
        });
    }

    public function onJobOutput(string $jobName, string $chunk, bool $isStderr): void
    {
        $this->buffers[$jobName] = ($this->buffers[$jobName] ?? '') . $this->captureInner(function () use ($jobName, $chunk, $isStderr) {
            $this->inner->onJobOutput($jobName, $chunk, $isStderr);
        });
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $body = ($this->buffers[$jobName] ?? '') . $this->captureInner(function () use ($jobName, $time) {
            $this->inner->onJobSuccess($jobName, $time);
        });
        unset($this->buffers[$jobName]);

        $this->emitSection($jobName, $body, true);
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $body = ($this->buffers[$jobName] ?? '') . $this->captureInner(function () use ($jobName, $time, $output) {
            $this->inner->onJobError($jobName, $time, $output);
        });
        unset($this->buffers[$jobName]);

        if (trim($output) !== '') {
            $body .= rtrim($output) . "\n";
        }

        $this->emitSection($jobName, $body, false);
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $body = $this->captureInner(function () use ($jobName, $reason) {
            $this->inner->onJobSkipped($jobName, $reason);
        });

        $this->emitSection($jobName, $body, true);
    }

    public function flush(): void
    {
        // Discard inner.flush() output: KO sections already include the tool's
        // error output. Letting framed error blocks leak here would print them
        // outside any section and duplicate the content shown inside KO sections.
        $this->captureInner(function () {
            $this->inner->flush();
        });
    }

    private function captureInner(callable $action): string
    {
        ob_start();
        try {
            $action();
        } finally {
            $captured = ob_get_clean();
        }
        return $captured === false ? '' : $captured;
    }

    private function emitSection(string $jobName, string $body, bool $collapsed): void
    {
        $this->sectionCounter++;
        $sectionId = 'githooks_job_' . $this->sectionCounter;
        $flag = $collapsed ? '[collapsed=true]' : '[collapsed=false]';

        $end = ($this->clock)();
        // section_start must reflect onJobStart time (BUG-16). Fallback to $end
        // when the close path was invoked without a prior onJobStart, which
        // preserves the previous behaviour for that branch.
        $start = $this->startTimes[$jobName] ?? $end;
        unset($this->startTimes[$jobName]);

        echo "\033[0Ksection_start:{$start}:{$sectionId}{$flag}\r\033[0K{$jobName}\n";
        echo $body;
        if ($body !== '' && substr($body, -1) !== "\n") {
            echo "\n";
        }
        echo "\033[0Ksection_end:{$end}:{$sectionId}\r\033[0K\n";
    }
}
