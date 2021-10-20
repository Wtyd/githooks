<?php

namespace Wtyd\GitHooks\Tools\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Exception\ModifiedButUnstagedFilesException;
use Wtyd\GitHooks\Tools\CodeSniffer;

/**
 * Ejecuta la libreria squizlabs/php_codesniffer
 */
class Phpcbf extends CodeSniffer
{
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'phpcbf';

        $this->setArguments($toolConfiguration->getToolConfiguration());
    }
}
