<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\Psalm;
use Tests\Doubles\PsalmFake;
use Wtyd\GitHooks\Registry\ToolRegistry;

class PsalmTest extends UnitTestCase
{
    /** @test */
    function psalm_is_a_supported_tool()
    {
        $this->assertTrue((new ToolRegistry())->isSupported('psalm'));
    }

    /** @test */
    function set_all_arguments_of_psalm_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'psalm.xml',
            'memory-limit' => '1G',
            'threads' => 4,
            'no-diff' => true,
            'output-format' => 'json',
            'plugin' => 'psalm-plugin',
            'use-baseline' => 'path/to/baseline.xml',
            'report' => 'psalm-report.xml',
            'otherArguments' => '--no-progress',
            'ignoreErrorsOnExit' => true,
            'failFast' => false,
            'paths' => ['src'],
        ];

        $toolConfiguration = new ToolConfiguration('psalm', $configuration, new ToolRegistry());
        $psalm = new PsalmFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $psalm->getExecutablePath());
        $this->assertEquals($configuration, $psalm->getArguments());
        $this->assertCount(count(PsalmFake::ARGUMENTS), $psalm->getArguments());
    }

    /** @test */
    function it_sets_psalm_in_executablePath_when_is_empty()
    {
        $configuration = [
            'config' => 'psalm.xml',
            'output-format' => 'json',
            'report' => 'psalm-report.xml',
            'otherArguments' => '--shepherd',
            'ignoreErrorsOnExit' => false,
        ];

        $toolConfiguration = new ToolConfiguration('psalm', $configuration, new ToolRegistry());
        $psalm = new PsalmFake($toolConfiguration);

        $this->assertEquals('psalm', $psalm->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments_from_psalm_configuration()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'psalm.xml',
            'output-format' => 'json',
            'report' => 'psalm-report.xml',
            'otherArguments' => '--shepherd',
            'ignoreErrorsOnExit' => true,
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('psalm', $configuration, new ToolRegistry());
        $psalm = new PsalmFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $psalm->getExecutablePath());
        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $psalm->getArguments());
    }

    /** @test */
    function it_sets_psalm_to_run_against_several_paths()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/psalm',
            'paths' => ['src', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('psalm', $configuration, new ToolRegistry());
        $psalm = new PsalmFake($toolConfiguration);

        $this->assertStringEndsWith('src app', $psalm->prepareCommand());
    }

    /**
     * @test
     * Psalm puede recibir varios argumentos, pero aquí solo comprobamos el comando generado
     */
    function it_prepares_psalm_command_with_all_arguments()
    {
        $configuration = [
            'executablePath' => 'vendor/bin/psalm',
            'config' => 'psalm.xml',
            'output-format' => 'json',
            'report' => 'psalm-report.xml',
            'otherArguments' => '--shepherd',
            'ignoreErrorsOnExit' => false,
            // 'paths' => ['src', 'app'],
        ];

        $toolConfiguration = new ToolConfiguration('psalm', $configuration, new ToolRegistry());
        $psalm = new PsalmFake($toolConfiguration);

        $cmd = $psalm->prepareCommand();
        $this->assertStringContainsString('vendor/bin/psalm', $cmd);
        $this->assertStringContainsString('--config=psalm.xml', $cmd);
        $this->assertStringContainsString('--output-format=json', $cmd);
        $this->assertStringContainsString('--report=psalm-report.xml', $cmd);
        $this->assertStringContainsString('--shepherd', $cmd);
    }
}
