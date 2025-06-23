<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpunit;
use Wtyd\GitHooks\Tools\Tool\PhpunitFake;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class PhpunitTest extends UnitTestCase
{
    /** @test */
    function phpunit_is_a_supported_tool()
    {
        $this->assertTrue(Phpunit::checkTool('phpunit'));
    }

    /** @test */
    function set_all_arguments_of_phpunit_from_configuration_file()
    {
        $configuration = [
            'group' => 'integration',
            'exclude-group' => 'slow',
            'filter' => 'testSomething',
            'otherArguments' => '--colors',
            'configuration' => 'path/to/configuration.xml',
            'log-junit' => 'junit.xml',
            'ignoreErrorsOnExit' => true,
            'executablePath' => 'path/to/phpunit',
        ];
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration);
        $phpunit = new PhpunitFake($toolConfiguration);
        $this->assertEquals($configuration['executablePath'], $phpunit->getExecutablePath());
        $this->assertEquals($configuration, $phpunit->getArguments());

        $this->assertCount(count(PhpUnitFake::ARGUMENTS), $phpunit->getArguments());
    }

    /** @test */
    function it_sets_phpunit_in_executablePath_when_is_empty()
    {
        $configuration = [
            'group' => 'integration',
            'exclude-group' => 'slow',
            'filter' => 'testSomething',
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
        ];
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration);
        $phpunit = new PhpunitFake($toolConfiguration);
        $this->assertEquals('phpunit', $phpunit->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments()
    {
        $configuration = [
            'executablePath' => 'path/tools/phpunit',
            'group' => 'integration',
            'exclude-group' => 'slow',
            'filter' => 'testSomething',
            'otherArguments' => '--colors',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration);
        $phpunit = new PhpunitFake($toolConfiguration);
         $this->assertEquals($configuration['executablePath'], $phpunit->getExecutablePath());
        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpunit->getArguments());
    }
}
