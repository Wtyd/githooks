<?php

namespace Tests\Utils\TestCase;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Container\RegisterBindings;

class UnitTestCase extends TestCase
{

    protected function registerBindings()
    {
        $register = new RegisterBindings();
        $register->register();
    }

    /**
     * Pattern for checking if a tool succeeded or failed
     *
     * @param string $tool
     * @param boolean $ok
     * @return string
     */
    protected function messageRegExp(string $tool, $ok = true): string
    {
        $timePattern = '(\d+ms|\d+\.\d{2}s|\d+m \d+s)';
        if ($ok) {
            return "%$tool - OK\. Time: $timePattern%";
        } else {
            return "%$tool - KO\. Time: $timePattern%";
        }
    }
}
