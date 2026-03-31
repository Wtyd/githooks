<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Phpunit;
use Tests\Doubles\PhpunitFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class PhpunitTest extends UnitTestCase
{
    /** @test */
    function phpunit_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('phpunit'));
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
            'failFast' => false,
            'executablePath' => 'path/to/phpunit',
        ];
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration, new ToolRegistry());
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
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration, new ToolRegistry());
        $phpunit = new PhpunitFake($toolConfiguration);
        $this->assertEquals('phpunit', $phpunit->getExecutablePath());
    }

    /** @test */
    function it_prepares_phpunit_command_with_all_arguments()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/phpunit',
            'group' => ['integration', 'unit'],
            'exclude-group' => ['slow'],
            'filter' => 'testSomething',
            'configuration' => 'path/to/phpunit.xml',
            'log-junit' => 'junit.xml',
            'otherArguments' => '--colors=always',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('phpunit', $configuration, new ToolRegistry());
        $phpunit = new PhpunitFake($toolConfiguration);

        $cmd = $phpunit->prepareCommand();
        $this->assertStringContainsString('vendor/bin/phpunit', $cmd);
        $this->assertStringContainsString('--group integration,unit', $cmd);
        $this->assertStringContainsString('--exclude-group slow', $cmd);
        $this->assertStringContainsString('--filter testSomething', $cmd);
        $this->assertStringContainsString('-c path/to/phpunit.xml', $cmd);
        $this->assertStringContainsString('--log-junit junit.xml', $cmd);
        $this->assertStringContainsString('--colors=always', $cmd);
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
        $toolConfiguration = new ToolConfiguration('phpunit', $configuration, new ToolRegistry());
        $phpunit = new PhpunitFake($toolConfiguration);
         $this->assertEquals($configuration['executablePath'], $phpunit->getExecutablePath());
        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $phpunit->getArguments());
    }
}
