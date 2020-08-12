<?php

namespace Tests\System\Utils;

use GitHooks\Tools\CheckSecurity;

class CheckSecurityFakeKo extends CheckSecurity
{
    public function execute()
    {
        //TODO: Add error message
        $this->exitCode = 1;
    }
}
