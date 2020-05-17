<?php

namespace Tests\System;

use GitHooks\Tools\CheckSecurity;

class CheckSecurityFakeKo extends CheckSecurity
{
    public function execute()
    {
        //TODO: Add error message
        // $this->exit = [
        //     'Symfony Security Check Report',
        //     '=============================',
        //     "",
        //     'No packages have known vulnerabilities.'
        // ];
        $this->exitCode = 1;
    }
}
