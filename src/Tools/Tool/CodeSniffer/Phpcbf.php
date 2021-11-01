<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library squizlabs/php_codesniffer
 */
class Phpcbf extends CodeSniffer
{
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'phpcbf';

        $this->setArguments($toolConfiguration->getToolConfiguration());
    }
}
