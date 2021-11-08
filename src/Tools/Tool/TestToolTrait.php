<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

/**
 * Trait for testing purposes. Gives public visibility for some methods and properties.
 */
trait TestToolTrait
{
    public function prepareCommand(): string
    {
        return parent::prepareCommand();
    }
    public function getArguments(): array
    {
        return $this->args;
    }

    public function getExecutablePath(): string
    {
        return $this->executablePath;
    }
}
