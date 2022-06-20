<?php

namespace Tests\Integration;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\FileReaderFake;
use Tests\Utils\TestCase\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\ConfigurationFile\ReadConfigurationFileAction;
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
            'phpcs' => ['phpcs'],
            'phpcbf' => ['phpcbf'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
            'phpstan' => ['phpstan'],
        ];
    }

    //TODO como toolsPreparer antes leia el fichero, lo fusionaba con cliArguments y creaba las herramientas todos los tests eran para eso.
    /**
     * @test
     * @dataProvider allToolsDataProvider
     *
     * Security-checker is not tested by the 'paths' argument.
     */
    function only_tool_argument_is_mandatory($tool)
    {
        $this->markTestIncomplete('estos tests no tienen sentido');
        $this->bindFakeTools();

        $configurationFileBuilder = new ConfigurationFileBuilder('');
        $configurationFile = $configurationFileBuilder->buildArray();

        $toolsPreparer = new ToolsPreparerFake(new ExecutionFactory());

        $configurationFile = new ConfigurationFile($configurationFileBuilder->buildArray(), $tool);
        $tools  = $toolsPreparer->__invoke($configurationFile);

        $this->assertEquals($configurationFile['Options']['execution'], $toolsPreparer->getExecutionMode());
        $this->assertEquals($configurationFile[$tool]['ignoreErrorsOnExit'], $tools[$tool]->getArguments()['ignoreErrorsOnExit']);
        $this->assertEquals($configurationFile[$tool]['executablePath'], $tools[$tool]->getArguments()['executablePath']);
        $this->assertEquals($configurationFile[$tool]['otherArguments'], $tools[$tool]->getArguments()['otherArguments']);
        $this->assertEquals($configurationFile[$tool]['paths'], $tools[$tool]->getArguments()['paths']);
    }
}
