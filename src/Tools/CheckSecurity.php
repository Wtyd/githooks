<?php
namespace GitHooks\Tools;

use GitHooks\Constants;

/**
 * Ejecuta la libreria funkjedi/composer-plugin-security-check
 * Encuentra vulnerabilidades en dependencias de composer.
 */
class CheckSecurity extends ToolAbstract
{
    public function __construct()
    {
        $this->installer = 'funkjedi/composer-plugin-security-check';

        $this->executable = 'composer check-security';

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        return $this->executable;
    }
}
