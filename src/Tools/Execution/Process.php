<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process as SymfonyProcess;

class Process extends SymfonyProcess
{
    protected $starttime;

    /** @inheritDoc */
    public function start(callable $callback = null, array $env = [])
    {
        $this->starttime = microtime(true);
        parent::start($callback, $env);
    }

    /**
     * @throws LogicException in case process is not started
     */
    public function getStartTime(): float
    {
        if (!$this->isStarted()) {
            throw new LogicException('Start time is only available after process start.');
        }

        return $this->starttime;
    }
}
