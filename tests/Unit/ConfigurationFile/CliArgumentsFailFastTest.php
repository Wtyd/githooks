<?php

declare(strict_types=1);

namespace Tests\Unit\ConfigurationFile;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

class CliArgumentsFailFastTest extends UnitTestCase
{
    /** @var ConfigurationFileBuilder */
    protected $configurationFileBuilder;

    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');
    }

    /** @test */
    function it_overrides_failFast_for_all_tools_when_tool_is_all()
    {
        $cliArguments = new CliArguments('all', '', null, '', '', '', 0, '', 'true');

        $configArray = $this->configurationFileBuilder
            ->setTools(['parallel-lint', 'phpstan'])
            ->buildArray();

        $result = $cliArguments->overrideArguments($configArray);

        $this->assertTrue($result['parallel-lint'][ToolAbstract::FAIL_FAST]);
        $this->assertTrue($result['phpstan'][ToolAbstract::FAIL_FAST]);
    }

    /** @test */
    function it_overrides_failFast_for_single_tool()
    {
        $cliArguments = new CliArguments('phpstan', '', null, '', '', '', 0, '', 'true');

        $configArray = $this->configurationFileBuilder
            ->setTools(['parallel-lint', 'phpstan'])
            ->buildArray();

        $result = $cliArguments->overrideArguments($configArray);

        $this->assertTrue($result['phpstan'][ToolAbstract::FAIL_FAST]);
        $this->assertArrayNotHasKey(ToolAbstract::FAIL_FAST, $result['parallel-lint']);
    }

    /** @test */
    function it_does_not_override_failFast_when_not_provided()
    {
        $cliArguments = new CliArguments('all', '', null, '', '', '', 0, '');

        $configArray = $this->configurationFileBuilder
            ->setTools(['parallel-lint', 'phpstan'])
            ->buildArray();

        $result = $cliArguments->overrideArguments($configArray);

        $this->assertArrayNotHasKey(ToolAbstract::FAIL_FAST, $result['parallel-lint']);
        $this->assertArrayNotHasKey(ToolAbstract::FAIL_FAST, $result['phpstan']);
    }

    /** @test */
    function it_can_disable_failFast_via_cli()
    {
        $cliArguments = new CliArguments('all', '', null, '', '', '', 0, '', 'false');

        $configArray = $this->configurationFileBuilder
            ->setTools(['parallel-lint', 'phpstan'])
            ->changeToolOption('parallel-lint', ['failFast' => true])
            ->buildArray();

        $result = $cliArguments->overrideArguments($configArray);

        $this->assertFalse($result['parallel-lint'][ToolAbstract::FAIL_FAST]);
        $this->assertFalse($result['phpstan'][ToolAbstract::FAIL_FAST]);
    }
}
