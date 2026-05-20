<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Concerns;

/**
 * Default no-op implementation of {@see \Wtyd\GitHooks\Output\OutputHandler::onJobWaiting()}
 * for handlers that do not need to render the FEAT-3 "waiting (deps)" state.
 *
 * Applied to TextOutputHandler, StreamingTextOutputHandler, ProgressOutputHandler,
 * NullOutputHandler and the CI decorators. DashboardOutputHandler implements
 * the method directly because it actually paints the state.
 */
trait OutputHandlerWaitingNoOp
{
    /**
     * @param string[] $waitingFor
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Trait default: by design the no-op ignores its arguments.
     */
    public function onJobWaiting(string $jobName, array $waitingFor): void
    {
        // No-op by design — see trait docblock.
    }
}
