<?php

namespace Tests\Integration;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\FileReaderFake;
use Tests\Utils\TestCase\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\LoadTools\ExecutionFactory;
use Wtyd\GitHooks\LoadTools\FullExecution;
use Wtyd\GitHooks\Tools\ToolsPreparerFake;

/**
 * All tests depends on CliArguments values
 */
class ToolsPreparerTest extends ConsoleTestCase
{

    public function allToolsDataProvider()
    {
        return [
            ['phpcs'],
            ['phpcbf'],
            ['phpmd'],
            ['phpcpd'],
            ['phpstan'],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsDataProvider
     *
     * Security-checker is not tested by the 'paths' argument.
     */
    function only_tool_argument_is_mandatory($tool)
    {
        $this->bindFakeTools();
        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $fileReader = new FileReaderFake();
        $configurationFile = $configurationFileBuilder->buildArray();
        $fileReader->mockConfigurationFile(
            $configurationFile
        );

        $toolsPreparer = new ToolsPreparerFake($fileReader, new ExecutionFactory());

        $cliArguments = new CliArguments($tool, '', null, '', '', '');

        $tools  = $toolsPreparer->__invoke($cliArguments);

        $this->assertEquals($configurationFile['Options']['execution'], $toolsPreparer->getExecutionMode());
        $this->assertEquals($configurationFile[$tool]['ignoreErrorsOnExit'], $tools[$tool]->getArguments()['ignoreErrorsOnExit']);
        $this->assertEquals($configurationFile[$tool]['executablePath'], $tools[$tool]->getArguments()['executablePath']);
        $this->assertEquals($configurationFile[$tool]['otherArguments'], $tools[$tool]->getArguments()['otherArguments']);
        $this->assertEquals($configurationFile[$tool]['paths'], $tools[$tool]->getArguments()['paths']);
    }

    /** @test */
    function raise_ToolIsNotSupportedException_when_tool_argument_is_empty()
    {
        $this->bindFakeTools();
        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $fileReader = new FileReaderFake();
        $configurationFile = $configurationFileBuilder->buildArray();
        $fileReader->mockConfigurationFile(
            $configurationFile
        );

        $toolsPreparer = new ToolsPreparerFake($fileReader, new ExecutionFactory());

        $cliArguments = new CliArguments('', '', null, '', '', '');

        $this->expectException(ToolIsNotSupportedException::class);
        $toolsPreparer->__invoke($cliArguments);
    }

    /** @test */
    function when_tool_is_all_arguments_otherArguments_executablePath_and_paths_are_ignored()
    {
        $this->bindFakeTools();
        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $fileReader = new FileReaderFake();
        $configurationFile = $configurationFileBuilder->setOptions(['execution' => 'fast'])->buildArray();
        $fileReader->mockConfigurationFile(
            $configurationFile
        );

        $toolsPreparer = new ToolsPreparerFake($fileReader, new ExecutionFactory());

        $ignoredArguments = [
            'otherArguments' => 'other arguments',
            'executablePath' => 'other executable',
            'paths' => './otherPath'
        ];

        $cliArguments = new CliArguments(
            'all',
            'full',
            true,
            $ignoredArguments['executablePath'],
            $ignoredArguments['executablePath'],
            $ignoredArguments['paths']
        );

        $tools  = $toolsPreparer->__invoke($cliArguments);

        $this->assertEqualsToConfigurationFile($configurationFile['phpcs'], $tools['phpcs']->getArguments());
        $this->assertNotEqualsToCliArguments($ignoredArguments, $tools['phpcs']->getArguments());

        $this->assertEqualsToConfigurationFile($configurationFile['phpcbf'], $tools['phpcbf']->getArguments());
        $this->assertNotEqualsToCliArguments($ignoredArguments, $tools['phpcbf']->getArguments());

        $this->assertEqualsToConfigurationFile($configurationFile['phpmd'], $tools['phpmd']->getArguments());
        $this->assertNotEqualsToCliArguments($ignoredArguments, $tools['phpmd']->getArguments());

        $this->assertEqualsToConfigurationFile($configurationFile['phpcpd'], $tools['phpcpd']->getArguments());
        $this->assertNotEqualsToCliArguments($ignoredArguments, $tools['phpcpd']->getArguments());

        $this->assertEqualsToConfigurationFile($configurationFile['phpstan'], $tools['phpstan']->getArguments());
        $this->assertNotEqualsToCliArguments($ignoredArguments, $tools['phpstan']->getArguments());
    }


    /** @test */
    function when_tool_is_all_only_override_execution_and_ignoreErrorsOnExit()
    {
        $this->bindFakeTools();
        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $fileReader = new FileReaderFake();
        $configurationFile = $configurationFileBuilder->setOptions(['execution' => 'fast'])->buildArray();
        $fileReader->mockConfigurationFile(
            $configurationFile
        );

        $toolsPreparer = new ToolsPreparerFake($fileReader, new ExecutionFactory());

        $cliArguments = new CliArguments('all', 'full', true, '', '', '');

        $tools  = $toolsPreparer->__invoke($cliArguments);

        $this->assertInstanceOf(FullExecution::class, $toolsPreparer->getStrategy());

        //All tools have overwritten ignoreErrorsOnExit
        $this->assertEquals(true, $tools['phpcs']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['phpcs']['ignoreErrorsOnExit'], $tools['phpcs']->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals(true, $tools['phpcbf']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['phpcbf']['ignoreErrorsOnExit'], $tools['phpcbf']->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals(true, $tools['phpstan']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['phpstan']['ignoreErrorsOnExit'], $tools['phpstan']->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals(true, $tools['phpmd']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['phpmd']['ignoreErrorsOnExit'], $tools['phpmd']->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals(true, $tools['phpcpd']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['phpcpd']['ignoreErrorsOnExit'], $tools['phpcpd']->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals(true, $tools['security-checker']->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile['security-checker']['ignoreErrorsOnExit'], $tools['security-checker']->getArguments()['ignoreErrorsOnExit']);
    }


    /**
     * @test
     * @dataProvider allToolsDataProvider
     */
    function overrides_execution_ignoreErrorsOnExit_otherArguments_executablePath_and_paths_when_tool_is_not_all($tool)
    {
        $this->bindFakeTools();

        $fileReader = new FileReaderFake();
        $fileReader->mockConfigurationFile(
            (new ConfigurationFileBuilder(''))->setOptions(['execution' => 'fast'])->buildArray()
        );

        $toolsPreparer = new ToolsPreparerFake($fileReader, new ExecutionFactory());

        $cliArguments = new CliArguments($tool, 'full', true, 'other argument', './executablePath', './otherPaths');

        $tools  = $toolsPreparer->__invoke($cliArguments);

        $this->assertInstanceOf(FullExecution::class, $toolsPreparer->getStrategy());

        $configurationFile = $fileReader->readFile();
        $this->assertEquals(true, $tools[$tool]->getArguments()['ignoreErrorsOnExit']);
        $this->assertNotEquals($configurationFile[$tool]['ignoreErrorsOnExit'], $tools[$tool]->getArguments()['ignoreErrorsOnExit']);

        $this->assertEquals('./executablePath', $tools[$tool]->getArguments()['executablePath']);
        $this->assertNotEquals($configurationFile[$tool]['executablePath'], $tools[$tool]->getArguments()['executablePath']);

        $this->assertEquals('other argument', $tools[$tool]->getArguments()['otherArguments']);
        $this->assertNotEquals($configurationFile[$tool]['otherArguments'], $tools[$tool]->getArguments()['otherArguments']);

        $this->assertEquals(['./otherPaths'], $tools[$tool]->getArguments()['paths']);
        $this->assertNotEquals($configurationFile[$tool]['paths'], $tools[$tool]->getArguments()['paths']);
    }

    protected function assertEqualsToConfigurationFile(array $toolConfigurationExpected, array $toolConfigurationActual)
    {
        $this->assertEquals($toolConfigurationExpected['otherArguments'], $toolConfigurationActual['otherArguments']);

        $this->assertEquals($toolConfigurationActual['executablePath'], $toolConfigurationActual['executablePath']);

        $this->assertEquals($toolConfigurationActual['paths'], $toolConfigurationActual['paths']);
    }

    protected function assertNotEqualsToCliArguments(array $cliArgumentsIgnored, array $toolConfigurationActual)
    {
        $this->assertNotEquals($cliArgumentsIgnored['otherArguments'], $toolConfigurationActual['otherArguments']);

        $this->assertNotEquals($cliArgumentsIgnored['executablePath'], $toolConfigurationActual['executablePath']);

        $this->assertNotEquals($cliArgumentsIgnored['paths'], $toolConfigurationActual['paths']);
    }
}
