<?php

namespace Tests\System;

use GitHooks\GitHooks;
use Illuminate\Container\Container;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\SystemTestCase;

class SystemTest extends SystemTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @test
     */
    function it_peta()
    {
        $rootPath = getcwd();
        $configFile = $rootPath . '/qa/githooks.yml';
        $container = Container::getInstance();
        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $configFile]);

        $githooks();


        $expects = "phpstan - OK. Time:";
        $this->assertStringContainsString($expects, $this->getActualOutput());
        // $this->assertStringNotContainsString();
        // $this->assertStringNotContainsStringIgnoringCase(),

        // fwrite(STDERR, $githooks());
        // fwrite(STDERR,'texto'."\n");
        // $this->dump();
    }
}
