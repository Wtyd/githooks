<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Execution;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ProcessFake extends Process
{
    /** @inheritDoc */
    protected $starttime;

    /** @inheritDoc */
    private $lastTime;

    /** @inheritDoc */
    private $isSuccessful = true;

    /** @inheritDoc */
    private $output;

    private $fakeTimeout = false;

    /**
     * Do nothing or invokes original method when we want to cause an error by timeout
     * @inheritDoc
     */
    public function start(callable $callback = null, array $env = [])
    {
        if ($this->fakeTimeout) {
            parent::start($callback, $env);
        } else {
            // Do nothing
            $this->starttime = microtime(true);
        }
    }

    /**
     * Mocks starttime
     * @return float
     */
    public function getStartTime(): float
    {
        return $this->starttime ?? 0.00;
    }

    /**
     * Mocks lastTime
     * @inheritDoc
     */
    public function getLastOutputTime(): float
    {
        if (!isset($this->lastTime)) {
            $mockedTime = rand(1, 700) / 13;
            $this->lastTime = $this->starttime + $mockedTime;
        }
        return $this->lastTime;
    }

    /**
     * Mocks the method. It is considered that the process has always finished (since it does not execute anything)
     * except when we want to cause a timeout error
     * @inheritDoc
     * @return bool
     */
    public function isTerminated(): bool
    {
        return $this->fakeTimeout ? false : true;
    }

    /**
     * Mocks the method.
     * @inheritDoc
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    /**
     * Only in MultiProcessExecution when the execution fails
     * @inheritDoc
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Only in ProcessExecution
     * @inheritDoc
     */
    public function wait(callable $callback = null)
    {
        return $this->isSuccessful ? 0 : 1;
    }

    /**
     * Mocks that the process fails.
     *
     * @return ProcessFake
     */
    public function setFail(): ProcessFake
    {
        $this->isSuccessful = false;
        $ex = explode(' ', $this->getCommandLine());


        $tools = array_keys(ToolAbstract::SUPPORTED_TOOLS);
        $nameTool = '';
        foreach ($tools as $tool) {
            if (preg_match("%$tool%", $ex[0])) {
                $nameTool = $tool;
                break;
            }
        }

        $this->output = "\nThe tool $nameTool mocks an error\n";

        return $this;
    }

    /**
     * Causes an error by timeout.
     *
     * @return void
     */
    public function triggerTimeout(): void
    {
        $this->setTimeout(0.000001);
        $this->isSuccessful = false;
        $this->fakeTimeout = true;
    }
}
