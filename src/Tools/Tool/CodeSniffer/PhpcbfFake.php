<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

/**
 * Class for testing purposes
 */
class PhpcbfFake extends Phpcbf
{
    public function prepareCommand(): string
    {
        return parent::prepareCommand();
    }

    public function getArguments(): array
    {
        return $this->args;
    }
}
