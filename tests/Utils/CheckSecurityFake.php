<?php

namespace Tests\Utils;

use GitHooks\Tools\CheckSecurity;

/**
 * This tool cannot be runned in testing environment
 */
class CheckSecurityFake extends CheckSecurity
{
    /**
     * Override this metod
     *
     * @return void
     */
    public function execute()
    {
        //Do nothing
    }

    /**
     * Fake succefull exit
     *
     * @return void
     */
    public function setOKExit()
    {
        $this->exit = [
            'Symfony Security Check Report',
            '=============================',
            "",
            'No packages have known vulnerabilities.'
        ];
        $this->exitCode = 0;

        return $this;
    }

    /**
     * Fake wrong exit
     *
     * @return void
     */
    public function setKOExit()
    {
        $this->exit = ['Some error for testing'];
        $this->exitCode = 1;

        return $this;
    }
}
