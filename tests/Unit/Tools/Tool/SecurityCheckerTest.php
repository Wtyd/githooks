<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\SecurityChecker;
use Wtyd\GitHooks\Tools\Tool\SecurityCheckerFake;

class SecurityCheckerTest extends UnitTestCase
{
    /** @test */
    function securityChecker_is_a_supported_tool()
    {
        $this->assertTrue(SecurityChecker::checkTool('security-checker'));
    }

    /** @test */
    function set_all_arguments_of_securityChecker_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/security-checker',
            'otherArguments' => '-format json',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('security-checker', $configuration);

        $securityChecker = new SecurityCheckerFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $securityChecker->getExecutablePath());

        $this->assertEquals($configuration, $securityChecker->getArguments());

        $this->assertCount(count(SecurityCheckerFake::ARGUMENTS), $securityChecker->getArguments());
    }

    /** @test */
    function it_sets_securityChecker_in_executablePath_when_is_empty()
    {
        $configuration = [
            'otherArguments' => '-format json',
            'ignoreErrorsOnExit' => true,
        ];

        $toolConfiguration = new ToolConfiguration('security-checker', $configuration);

        $securityChecker = new SecurityCheckerFake($toolConfiguration);

        $this->assertEquals('local-php-security-checker', $securityChecker->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_securityChecker_configuration()
    {
        $configuration = [
            'executablePath' => 'path/tools/security-checker',
            'otherArguments' => '-format json',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('security-checker', $configuration);

        $securityChecker = new SecurityCheckerFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $securityChecker->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $securityChecker->getArguments());
    }
}
