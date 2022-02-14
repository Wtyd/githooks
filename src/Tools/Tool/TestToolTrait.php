<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

/**
 * Trait for testing purposes. Gives public visibility for some methods and properties.
 */
trait TestToolTrait
{
    /** @var int Successfully per default */
    protected $fakeExitCode = 0;

    /**
     * Offers visibility for the protected method
     *
     * @return string
     */
    public function prepareCommand(): string
    {
        return parent::prepareCommand();
    }

    /**
     * Offers visibility for the atributte
     *
     * @return array
     */
    public function getArguments(): array
    {
        return $this->args;
    }

    /**
     * Offers visibility for the atributte
     *
     * @return string
     */
    public function getExecutablePath(): string
    {
        return $this->args[ToolAbstract::EXECUTABLE_PATH_OPTION];
    }



    /**
     * Sets what will return the execution of the tool
     *
     * @param int $exitCode
     * @param array $exitText Each element of the array is a string simulating the console output
     * @return ToolAbstract
     */
    public function fakeExit(int $exitCode, array $exitText): ToolAbstract
    {
        $this->fakeExitCode = $exitCode;
        $this->exit = $exitText;

        return $this;
    }

    /**
     * Fakes the method for avoid tool run
     *
     * @param bool $withLiveOutput
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(bool $withLiveOutput): void
    {
        $this->exitCode = $this->fakeExitCode;
        if ($withLiveOutput) {
            $this->printExit();
        }
    }

    /**
     * Fake passthru execution
     *
     * @return void
     */
    protected function printExit()
    {
        echo "\n";
        foreach ($this->exit as $value) {
            echo $value;
        }
    }
}
