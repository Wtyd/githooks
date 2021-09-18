<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Ejecuta la libreria funkjedi/composer-plugin-security-check
 * Encuentra vulnerabilidades en dependencias de composer.
 */
class CheckSecurity extends ToolAbstract
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @param ToolConfiguration $toolConfiguration
     */
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'composer check-security';
    }

    protected function prepareCommand(): string
    {
        return $this->executable;
    }
}
