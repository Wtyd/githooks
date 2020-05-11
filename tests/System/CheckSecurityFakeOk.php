<?php

namespace Tests\System;

use GitHooks\Tools\CheckSecurity;

class CheckSecurityFakeOk extends CheckSecurity
{
    public function execute()
    {
        $this->exit = [
            'Symfony Security Check Report',
            '=============================',
            "",
            'No packages have known vulnerabilities.'
        ];
        $this->exitCode = 0;
    }
}
