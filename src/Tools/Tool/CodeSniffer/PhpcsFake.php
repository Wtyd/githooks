<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

/**
 * Class for testing purposes
 */
class PhpcsFake extends Phpcs
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
