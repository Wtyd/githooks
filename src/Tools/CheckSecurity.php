<?php

namespace GitHooks\Tools;

use GitHooks\Tools\Exception\ExecutableNotFoundException;

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

    /**
     * Devuelve la primera versión del ejecutable que encuentra (como .phar, en local o global). Si no encuentra ninguna versión lanza excepción.
     * Sobreescritura del método padre.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        //TODO por un lado el composer puede estar a nivel global o puede ser un phar y por otro lado el ejecutable
        // $phar = 'php composer.phar show ' . $this->installer;
        $local = 'composer show ' . $this->installer;
        $global = 'composer global show ' . $this->installer;

        // if ($this->libraryCheck($phar)) {
        //     return  $this->executable . '.phar';
        // }

        if ($this->libraryCheck($local)) {
            return $this->executable;
        }

        if ($this->libraryCheck($global)) {
            $this->executable = 'composer global check-security';
            return $this->executable;
        }

        throw ExecutableNotFoundException::forExec($this->executable);
    }
}
