<?php

namespace GitHooks\Utils;

class GitFiles
{
    /**
     * Array de ficheros modificados desde el último commit
     *
     * @return array
     */
    public function getModifiedFiles(): array
    {
        $modifiedFiles = null;
        exec('git diff --name-only', $modifiedFiles);

        return $modifiedFiles;
    }

    /**
     * Comprueba que composer.json o composer.lock han sido modificados
     *
     * @return boolean. True cuando se ha modificado alguna librería de composer.
     */
    public function isComposerModified(): bool
    {
        //TODO Corregir el error que hace que por pantalla se muestre sh: 1: Syntax error: Unterminated quoted string
        $composer = shell_exec('git diff --cached --name-only --diff-filter=ACM |^composer.json$\\|^composer.lock$');

        if (empty($composer)) {
            return false;
        }

        return true;
    }
}
