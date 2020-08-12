<?php

namespace Tests;

use GitHooks\ConfigurationErrors;
use PHPUnit\Framework\TestCase;

class ConfigurationErrorsTest extends TestCase
{
    /** @test */
    function it_set_Option_errors_in_empty_object()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setOptionsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_set_Option_errors_in_object_with_Option_errors()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setOptionsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getOptionsErrors());

        $newErrors = ['My error 3', 'My error 4'];

        $baseErrors->setOptionsErrors($newErrors);

        $this->assertEquals($newErrors, $baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_add_Option_errors_in_empty_object()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->addOptionsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_add_Option_errors_in_object_with_Option_errors()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setOptionsErrors($addedErrors);

        $newErrors = ['My error 3', 'My error 4'];

        $baseErrors->addOptionsErrors($newErrors);

        $totalErrors = array_merge($addedErrors, $newErrors);

        $this->assertEquals($totalErrors, $baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_set_Option_warnings_in_empty_object()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setOptionsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_set_Option_warnings_in_object_with_Option_warnings()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setOptionsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getOptionsWarnings());

        $newWarnings = ['My warning 3', 'My warning 4'];

        $baseWarnings->setOptionsWarnings($newWarnings);

        $this->assertEquals($newWarnings, $baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_add_Option_warnings_in_empty_object()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->addOptionsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_add_Option_warnings_in_object_with_Option_warnings()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setOptionsWarnings($addedWarnings);

        $newErrors = ['My warning 3', 'My warning 4'];

        $baseWarnings->addOptionsWarnings($newErrors);

        $totalErrors = array_merge($addedWarnings, $newErrors);

        $this->assertEquals($totalErrors, $baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_set_Tool_errors_in_empty_object()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setToolsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_set_Tool_errors_in_object_with_Tool_errors()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setToolsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getToolsErrors());

        $newErrors = ['My error 3', 'My error 4'];

        $baseErrors->setToolsErrors($newErrors);

        $this->assertEquals($newErrors, $baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_add_Tool_errors_in_empty_object()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->addToolsErrors($addedErrors);

        $this->assertEquals($addedErrors, $baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_add_Tool_errors_in_object_with_Tool_errors()
    {
        $baseErrors = new ConfigurationErrors();

        $addedErrors = ['My error 1', 'My error 2'];

        $baseErrors->setToolsErrors($addedErrors);

        $newErrors = ['My error 3', 'My error 4'];

        $baseErrors->addToolsErrors($newErrors);

        $totalErrors = array_merge($addedErrors, $newErrors);

        $this->assertEquals($totalErrors, $baseErrors->getToolsErrors());
        $this->assertEmpty($baseErrors->getOptionsErrors());
        $this->assertEmpty($baseErrors->getAllWarnings());
    }

    /** @test */
    function it_set_Tool_warnings_in_empty_object()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setToolsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_set_Tool_warnings_in_object_with_Tool_warnings()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setToolsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getToolsWarnings());

        $newWarnings = ['My warning 3', 'My warning 4'];

        $baseWarnings->setToolsWarnings($newWarnings);

        $this->assertEquals($newWarnings, $baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_add_Tool_warnings_in_empty_object()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->addToolsWarnings($addedWarnings);

        $this->assertEquals($addedWarnings, $baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_add_Tool_warnings_in_object_with_Tool_warnings()
    {
        $baseWarnings = new ConfigurationErrors();

        $addedWarnings = ['My warning 1', 'My warning 2'];

        $baseWarnings->setToolsWarnings($addedWarnings);

        $newErrors = ['My warning 3', 'My warning 4'];

        $baseWarnings->addToolsWarnings($newErrors);

        $totalErrors = array_merge($addedWarnings, $newErrors);

        $this->assertEquals($totalErrors, $baseWarnings->getToolsWarnings());
        $this->assertEmpty($baseWarnings->getOptionsWarnings());
        $this->assertEmpty($baseWarnings->getAllErrors());
    }

    /** @test */
    function it_can_set_errors()
    {
        $confErrors = new ConfigurationErrors();

        $optionsErrors = ['option error 1', 'option error 2'];

        $toolsErrors = ['tool error 1', 'tool error 2'];

        $confErrors->setErrors($optionsErrors, $toolsErrors);

        $this->assertEquals($optionsErrors, $confErrors->getOptionsErrors());
        $this->assertEquals($toolsErrors, $confErrors->getToolsErrors());
        $this->assertEmpty($confErrors->getAllWarnings());
    }

    /** @test */
    function it_can_set_warnings()
    {
        $confErrors = new ConfigurationErrors();

        $optionsWarnings = ['option warnings 1', 'option warnings 2'];

        $toolsWarnings = ['tool warnings 1', 'tool warnings 2'];

        $confErrors->setWarnings($optionsWarnings, $toolsWarnings);

        $this->assertEquals($optionsWarnings, $confErrors->getOptionsWarnings());
        $this->assertEquals($toolsWarnings, $confErrors->getToolsWarnings());
        $this->assertEmpty($confErrors->getAllErrors());
    }

    public function setAndAddProblemsProvider()
    {
        return [
            'Set empty problems' => [
                'OptionsProblems' => [],
                'ToolssProblems' => []
            ],
            'Set problems' => [
                'OptionsProblems' => ['option problem 1', 'option problem 2'],
                'ToolssProblems' => ['tool problem 1', 'tool problem 2']
            ]
        ];
    }

    /**
     * @test
     * @dataProvider setAndAddProblemsProvider
     */
    function it_can_set_errors_and_then_add_more_errors($optionsErrors, $toolsErrors)
    {
        $confErrors = new ConfigurationErrors();

        $confErrors->setErrors($optionsErrors, $toolsErrors);

        $newOptionsErrors = ['option error 3', 'option error 4'];

        $newToolsErrors = ['tool error 3', 'tool error 4'];

        $confErrors->addErrors($newOptionsErrors, $newToolsErrors);

        $expectedOptionsErrors = array_merge($optionsErrors, $newOptionsErrors);

        $expectedToolsErrors = array_merge($toolsErrors, $newToolsErrors);

        $this->assertEquals($expectedOptionsErrors, $confErrors->getOptionsErrors());
        $this->assertEquals($expectedToolsErrors, $confErrors->getToolsErrors());
        $this->assertEmpty($confErrors->getAllWarnings());
    }

    /**
     * @test
     * @dataProvider setAndAddProblemsProvider
     */
    function it_can_set_warnings_and_then_add_more_warnings($optionsWarnings, $toolsWarnings)
    {
        $confWarnings = new ConfigurationErrors();

        $confWarnings->setWarnings($optionsWarnings, $toolsWarnings);

        $newOptionsWarnings = ['option error 3', 'option error 4'];

        $newToolsWarnings = ['tool error 3', 'tool error 4'];

        $confWarnings->addWarnings($newOptionsWarnings, $newToolsWarnings);

        $expectedOptionsWarnings = array_merge($optionsWarnings, $newOptionsWarnings);

        $expectedToolsWarnings = array_merge($toolsWarnings, $newToolsWarnings);

        $this->assertEquals($expectedOptionsWarnings, $confWarnings->getOptionsWarnings());
        $this->assertEquals($expectedToolsWarnings, $confWarnings->getToolsWarnings());
        $this->assertEmpty($confWarnings->getAllErrors());
    }

    /** @test */
    function it_check_for_errors()
    {
        $confErrors = new ConfigurationErrors();

        $this->assertFalse($confErrors->hasErrors());

        $confErrors->setErrors(['My option error'], []);

        $this->assertTrue($confErrors->hasErrors());

        $confErrors->setErrors([], []);

        $this->assertFalse($confErrors->hasErrors());

        $confErrors->setErrors([], ['My tool error']);

        $this->assertTrue($confErrors->hasErrors());

        $confErrors->setErrors(['My option error'], ['My tool error']);

        $this->assertTrue($confErrors->hasErrors());
    }

    /** @test */
    function it_check_for_warnings()
    {
        $confErrors = new ConfigurationErrors();

        $this->assertFalse($confErrors->hasWarnings());

        $confErrors->setWarnings(['My option warning'], []);

        $this->assertTrue($confErrors->hasWarnings());

        $confErrors->setWarnings([], []);

        $this->assertFalse($confErrors->hasWarnings());

        $confErrors->setWarnings([], ['My tool warning']);

        $this->assertTrue($confErrors->hasWarnings());

        $confErrors->setWarnings(['My option warning'], ['My tool warning']);

        $this->assertTrue($confErrors->hasWarnings());
    }
}
