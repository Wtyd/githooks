<?php

namespace Tests\Utils\TestCase;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Container\RegisterBindings;
use Tests\Utils\Traits\RetroCompatibilityAssertsTrait;

class UnitTestCase extends TestCase
{
    use RetroCompatibilityAssertsTrait;

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
        if ($ok) {
            return "%$tool - OK\. Time: \d+\.\d{2}%";
        } else {
            return "%$tool - KO\. Time: \d+\.\d{2}%";
        }
    }
}
