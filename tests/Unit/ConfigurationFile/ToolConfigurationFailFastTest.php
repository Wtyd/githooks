<?php

declare(strict_types=1);

namespace Tests\Unit\ConfigurationFile;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ToolConfigurationFailFastTest extends UnitTestCase
{
    /** @test */
    function it_accepts_failFast_as_true()
    {
        $toolConfig = new ToolConfiguration('parallel-lint', [
            'paths' => ['src'],
            'failFast' => true,
        ], new ToolRegistry());

        $config = $toolConfig->getToolConfiguration();

        $this->assertTrue($config['failFast']);
        $this->assertTrue($toolConfig->isEmptyWarnings());
    }

    /** @test */
    function it_accepts_failFast_as_false()
    {
        $toolConfig = new ToolConfiguration('parallel-lint', [
            'paths' => ['src'],
            'failFast' => false,
        ], new ToolRegistry());

        $config = $toolConfig->getToolConfiguration();

        $this->assertFalse($config['failFast']);
        $this->assertTrue($toolConfig->isEmptyWarnings());
    }

    /** @test */
    function it_warns_and_defaults_to_false_when_failFast_is_not_boolean()
    {
        $toolConfig = new ToolConfiguration('parallel-lint', [
            'paths' => ['src'],
            'failFast' => 'yes',
        ], new ToolRegistry());

        $config = $toolConfig->getToolConfiguration();

        $this->assertFalse($config['failFast']);
        $this->assertFalse($toolConfig->isEmptyWarnings());
        $this->assertContains(
            "Value for 'failFast' in tool parallel-lint must be boolean. This option will be ignored.",
            $toolConfig->getWarnings()
        );
    }

    /** @test */
    function it_warns_when_both_failFast_and_ignoreErrorsOnExit_are_true()
    {
        $toolConfig = new ToolConfiguration('parallel-lint', [
            'paths' => ['src'],
            'failFast' => true,
            'ignoreErrorsOnExit' => true,
        ], new ToolRegistry());

        $config = $toolConfig->getToolConfiguration();

        // failFast takes priority: ignoreErrorsOnExit is forced to false
        $this->assertTrue($config['failFast']);
        $this->assertFalse($config['ignoreErrorsOnExit']);
        $this->assertFalse($toolConfig->isEmptyWarnings());

        $found = false;
        foreach ($toolConfig->getWarnings() as $warning) {
            if (strpos($warning, 'failFast') !== false && strpos($warning, 'ignoreErrorsOnExit') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected a warning about failFast/ignoreErrorsOnExit conflict');
    }

    /** @test */
    function it_does_not_warn_when_failFast_is_true_and_ignoreErrorsOnExit_is_false()
    {
        $toolConfig = new ToolConfiguration('parallel-lint', [
            'paths' => ['src'],
            'failFast' => true,
            'ignoreErrorsOnExit' => false,
        ], new ToolRegistry());

        $config = $toolConfig->getToolConfiguration();

        $this->assertTrue($config['failFast']);
        $this->assertFalse($config['ignoreErrorsOnExit']);
        $this->assertTrue($toolConfig->isEmptyWarnings());
    }
}
