<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecution;

class MultiProcessesExecutionFake extends MultiProcessesExecution
{
    use ExecutionFakeTrait;

    /**
     * @var int Iterations of the do-while observed by hasPendingWork(). Public
     *          so tests can inspect or reset it.
     */
    public int $iterations = 0;

    /**
     * @var int Hard cap on do-while iterations. Set generously for the suite
     *          (each iteration polls O(numTools) processes; six tools converge
     *          in ≲12 iterations). Override per-test if a stress scenario
     *          legitimately needs more.
     */
    public int $iterationCap = 200;

    /**
     * Liveness guard against deadlock regressions in runProcesses(). Without
     * this cap the do-while spins indefinitely when a guard-protecting branch
     * is removed (addProcessToQueue, finishExecution, the catch blocks, the
     * hasPendingWork early-exit), and Infection has to wait its 120s timeout
     * before recording the mutant as TIMED_OUT.
     *
     * Throwing here escapes to the OUTER `catch (Throwable)` on line 55 of
     * MultiProcessesExecution::runProcesses() (the inner catch on line 51
     * cannot snare it because the while condition is evaluated outside the
     * inner try/catch). The outer handler registers it as a 'General' error
     * so callers can detect the cap was hit. The bundled
     * `run_loop_terminates_under_adversarial_inputs` test asserts the absence
     * of that key for the contract; existing tests benefit from the cap
     * implicitly — instead of a 120s hang on regressed code they fail fast
     * with a clear diagnostic and Infection records a kill rather than a
     * timeout.
     */
    protected function hasPendingWork(int $totalProcesses): bool
    {
        if (++$this->iterations > $this->iterationCap) {
            throw new \Error(
                "MultiProcessesExecution::runProcesses() did not converge after {$this->iterations} iterations — "
                    . 'check addProcessToQueue, finishExecution and the catch blocks for guard regressions.'
            );
        }
        return parent::hasPendingWork($totalProcesses);
    }
}
