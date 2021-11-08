<?php

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library squizlabs/php_codesniffer
 */
class Phpcs extends CodeSniffer
{
    /**
     * @var array
     */
    protected $args;

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'phpcs';
        // dd($this);
        $this->setArguments($toolConfiguration->getToolConfiguration());
    }
}
