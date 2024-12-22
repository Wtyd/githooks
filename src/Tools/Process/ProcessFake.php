<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Process;

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
    protected $status;

    private $fakeTimeout = false;
    private $outputFake;
    private $errorOutputFake;

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
        $this->status = self::STATUS_STARTED;
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
     * @inheritDoc
     */
    public function getOutput()
    {
        if (! $this->isSuccessful) {
            return $this->outputFake;
        }
        return '';
    }


    /**
     * @inheritDoc
     */
    public function getErrorOutput()
    {
        if (! $this->isSuccessful) {
            return $this->errorOutputFake;
        }
        return '';
    }

    /**
     * Only in ProcessExecution
     * @inheritDoc
     */
    public function wait(callable $callback = null): int
    {
        return $this->isSuccessful ? 0 : 1;
    }

    private function extractToolName(): string
    {
        $ex = explode(' ', $this->getCommandLine());

        $tools = array_keys(ToolAbstract::SUPPORTED_TOOLS);
        $nameTool = '';
        foreach ($tools as $tool) {
            if (preg_match("%$tool%", $ex[0])) {
                $nameTool = $tool;
                break;
            }
        }
        return $nameTool;
    }

    /**
     * Mocks that the process fails.
     *
     * @return ProcessFake
     */
    public function setFail(): ProcessFake
    {
        $this->isSuccessful = false;
        $nameTool = $this->extractToolName();

        // getErrorOutput() o getOutput() o Exeception
        $this->errorOutputFake = "\nThe tool $nameTool mocks an error\n";

        return $this;
    }

    public function setFailByException(): ProcessFake
    {
        $this->isSuccessful = false;
        $nameTool = $this->extractToolName();

        // getErrorOutput() o getOutput() o Exeception
        // $this->errorOutputFake = "\nThe tool $nameTool mocks an error\n";

        return $this;
    }

    public function setFailByFoundedErrors(): ProcessFake
    {
        $this->isSuccessful = false;
        $nameTool = $this->extractToolName();
        $this->errorOutputFake = "\n$nameTool fakes an error\n";
        return $this;
    }

    public function setFailByErrorsWithExitCode0(): ProcessFake
    {
        $this->isSuccessful = false;
        $nameTool = $this->extractToolName();
        $this->outputFake = "\n$nameTool fakes an error with exit code 0\n";
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
