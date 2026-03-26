<?php

declare(strict_types=1);

namespace Tests\Unit\ConfigurationFile;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class ToolConfigurationExecutionTest extends UnitTestCase
{
    /** @test */
    function it_stores_execution_mode_when_present()
    {
        $toolConfig = new ToolConfiguration('phpcs', [
            'paths' => ['src'],
            'execution' => 'fast',
        ]);

        $this->assertEquals('fast', $toolConfig->getExecution());
        $this->assertTrue($toolConfig->hasExecution());
    }

    /** @test */
    function it_returns_null_execution_when_not_set()
    {
        $toolConfig = new ToolConfiguration('phpcs', [
            'paths' => ['src'],
        ]);

        $this->assertNull($toolConfig->getExecution());
        $this->assertFalse($toolConfig->hasExecution());
    }

    /** @test */
    function it_accepts_full_execution_mode()
    {
        $toolConfig = new ToolConfiguration('phpmd', [
            'paths' => ['src'],
            'rules' => 'unusedcode',
            'execution' => 'full',
        ]);

        $this->assertEquals('full', $toolConfig->getExecution());
        $this->assertTrue($toolConfig->hasExecution());
        $this->assertTrue($toolConfig->isEmptyWarnings());
    }

    /** @test */
    function it_warns_and_ignores_invalid_execution_value()
    {
        $toolConfig = new ToolConfiguration('phpcs', [
            'paths' => ['src'],
            'execution' => 'turbo',
        ]);

        $this->assertNull($toolConfig->getExecution());
        $this->assertFalse($toolConfig->hasExecution());
        $this->assertFalse($toolConfig->isEmptyWarnings());
        $this->assertContains(
            "Value 'turbo' for 'execution' in tool phpcs is not valid. Valid values: full, fast. This option will be ignored.",
            $toolConfig->getWarnings()
        );
    }

    /** @test */
    function it_does_not_pass_execution_to_tool_arguments()
    {
        $toolConfig = new ToolConfiguration('phpcs', [
            'paths' => ['src'],
            'execution' => 'fast',
        ]);

        $this->assertArrayNotHasKey('execution', $toolConfig->getToolConfiguration());
    }

    /** @test */
    function it_preserves_other_arguments_when_execution_is_set()
    {
        $toolConfig = new ToolConfiguration('phpcs', [
            'paths' => ['src'],
            'standard' => 'PSR12',
            'execution' => 'fast',
            'ignoreErrorsOnExit' => false,
        ]);

        $config = $toolConfig->getToolConfiguration();
        $this->assertEquals(['src'], $config['paths']);
        $this->assertEquals('PSR12', $config['standard']);
        $this->assertFalse($config['ignoreErrorsOnExit']);
        $this->assertArrayNotHasKey('execution', $config);
        $this->assertEquals('fast', $toolConfig->getExecution());
    }

    /**
     * @test
     * @dataProvider allAccelerableToolsProvider
     */
    function it_works_with_all_accelerable_tools($tool, $config)
    {
        $config['execution'] = 'fast';
        $toolConfig = new ToolConfiguration($tool, $config);

        $this->assertEquals('fast', $toolConfig->getExecution());
        $this->assertArrayNotHasKey('execution', $toolConfig->getToolConfiguration());
    }

    public function allAccelerableToolsProvider()
    {
        return [
            'phpcs' => ['phpcs', ['paths' => ['src']]],
            'phpcbf' => ['phpcbf', ['paths' => ['src']]],
            'phpmd' => ['phpmd', ['paths' => ['src'], 'rules' => 'unusedcode']],
            'phpstan' => ['phpstan', ['paths' => ['src']]],
            'parallel-lint' => ['parallel-lint', ['paths' => ['src']]],
            'psalm' => ['psalm', ['paths' => ['src']]],
        ];
    }
}
