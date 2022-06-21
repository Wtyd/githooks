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

    /** @return string Offers visibility for the protected method */
    public function prepareCommand(): string
    {
        return parent::prepareCommand();
    }

    /** @return array Offers visibility for the protected method */
    public function getArguments(): array
    {
        return $this->args;
    }

    /** @return string Offers visibility for the protected method */
    public function getExecutablePath(): string
    {
        return $this->args[ToolAbstract::EXECUTABLE_PATH_OPTION];
    }
}
