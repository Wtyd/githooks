<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Process\Process;

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

    /** @inheritDoc */
    private $exitcode;

    private $fakeTimeout = false;
    private $outputFake;
    private $errorOutputFake;
    private $mustRaiseException = false;
    private $fakeExitCode = 0;

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
        if ($this->mustRaiseException) {
            throw new ProcessFailedException($this);
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
     * @inheritDoc
     */
    public function getOutput(): string
    {
        if (! $this->isSuccessful) {
            return $this->outputFake;
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getErrorOutput(): string
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

        $tools = [
            ToolRegistry::PHPCS,
            ToolRegistry::PHPCBF,
            ToolRegistry::SECURITY_CHECKER,
            ToolRegistry::PARALLEL_LINT,
            ToolRegistry::MESS_DETECTOR,
            ToolRegistry::COPYPASTE_DETECTOR,
            ToolRegistry::PHPSTAN,
            ToolRegistry::PHPUNIT,
            ToolRegistry::PSALM,
            ToolRegistry::SCRIPT,
        ];
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
        $this->fakeExitCode = 2;
        $nameTool = $this->extractToolName();

        // getErrorOutput() o getOutput() o Exeception
        $this->errorOutputFake = "\nThe tool $nameTool mocks an error\n";

        return $this;
    }

    public function setFailByException(): ProcessFake
    {
        $this->isSuccessful = false;
        $this->mustRaiseException = true;
        $nameTool = $this->extractToolName();
        $this->outputFake = $this->errorOutputFake = "$nameTool fakes an exception";
        $this->exitcode = 1;
        return $this;
    }

    public function setFailByFoundedErrors(): ProcessFake
    {
        $this->isSuccessful = false;
        $this->fakeExitCode = 2;
        $nameTool = $this->extractToolName();
        $this->errorOutputFake = "\n$nameTool fakes an error\n";
        return $this;
    }

    public function setFailByFoundedErrorsInNormalOutput(): ProcessFake
    {
        $this->isSuccessful = false;
        $this->fakeExitCode = 2;
        $nameTool = $this->extractToolName();
        $this->outputFake = "\n$nameTool fakes an error in normal output\n";
        $this->errorOutputFake = '';
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

    /**
     * Simulates a tool that applied fixes (e.g. phpcbf exit code 1).
     * The process is not successful (exit != 0) but the tool considers
     * the exit code as a fix applied.
     *
     * @param int $exitCode
     * @return ProcessFake
     */
    public function setFixApplied(int $exitCode = 1): ProcessFake
    {
        $this->isSuccessful = false;
        $this->fakeExitCode = $exitCode;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getExitCode(): ?int
    {
        return $this->fakeExitCode;
    }
}
