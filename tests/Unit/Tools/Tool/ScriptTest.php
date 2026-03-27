<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Script;
use Wtyd\GitHooks\Tools\Tool\ScriptFake;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class ScriptTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        ToolAbstract::resetScriptAlias();
        parent::tearDown();
    }

    /** @test */
    function script_is_a_supported_tool()
    {
        $this->assertTrue(Script::checkTool('script'));
    }

    /** @test */
    function set_all_arguments_of_script_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'otherArguments' => 'fix --dry-run',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $script = new ScriptFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $script->getExecutablePath());

        $this->assertEquals($configuration, $script->getArguments());

        $this->assertCount(count(ScriptFake::ARGUMENTS), $script->getArguments());
    }

    /** @test */
    function it_throws_exception_when_executablePath_is_empty()
    {
        $configuration = [
            'otherArguments' => '--verbose',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("The 'executablePath' option is required for the 'script' tool.");

        new Script($toolConfiguration);
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_script_configuration()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'otherArguments' => 'fix --dry-run',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value',
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $script = new ScriptFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $script->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $script->getArguments());
    }

    /** @test */
    function it_builds_command_with_executablePath_and_otherArguments()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'otherArguments' => 'fix --dry-run --config=.php-cs-fixer.php',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $script = new ScriptFake($toolConfiguration);

        $this->assertEquals(
            'vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.php',
            $script->prepareCommand()
        );
    }

    /** @test */
    function it_builds_command_with_only_executablePath()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/infection',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $script = new ScriptFake($toolConfiguration);

        $this->assertEquals('vendor/bin/infection', $script->prepareCommand());
    }

    /** @test */
    function it_uses_executablePath_as_display_name()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/php-cs-fixer',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('script', $configuration);

        $script = new ScriptFake($toolConfiguration);

        // The executable property (display name) should be the executablePath value
        $this->assertEquals('vendor/bin/php-cs-fixer', $script->getExecutablePath());
        $this->assertEquals('vendor/bin/php-cs-fixer', $script->prepareCommand());
    }

    /** @test */
    function it_accepts_custom_name_as_alias_via_registerScriptAlias()
    {
        ToolAbstract::registerScriptAlias('php-cs-fixer');

        $this->assertTrue(ToolAbstract::checkTool('php-cs-fixer'));
        $this->assertEquals('script', ToolAbstract::resolveToolName('php-cs-fixer'));
        $this->assertEquals('php-cs-fixer', ToolAbstract::getScriptAlias());
    }

    /** @test */
    function it_resolves_non_alias_names_unchanged()
    {
        ToolAbstract::registerScriptAlias('php-cs-fixer');

        $this->assertEquals('phpstan', ToolAbstract::resolveToolName('phpstan'));
        $this->assertEquals('script', ToolAbstract::resolveToolName('script'));
    }

    /** @test */
    function it_throws_exception_when_alias_conflicts_with_existing_tool()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("conflicts with an existing supported tool");

        ToolAbstract::registerScriptAlias('phpstan');
    }

    /** @test */
    function it_resets_alias_correctly()
    {
        ToolAbstract::registerScriptAlias('php-cs-fixer');
        $this->assertEquals('php-cs-fixer', ToolAbstract::getScriptAlias());

        ToolAbstract::resetScriptAlias();
        $this->assertNull(ToolAbstract::getScriptAlias());
        $this->assertFalse(ToolAbstract::checkTool('php-cs-fixer'));
    }
}
