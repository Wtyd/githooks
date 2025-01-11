<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Execution;

use Tests\Mock;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Tools\Process\Execution\MultiProcessesExecutionFake;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\Printer;

/**
 * Applied pairwise testing strategy. See tests cases in the link https://pairwise.teremokgames.com/5fujg/
 */
class MultiProcessesExecutionTest extends UnitTestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    protected const ALL_TOOLS = 'all';

    protected $configurationFileBuilder;

    protected $toolsFactory;

    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');

        $this->toolsFactory = new ToolsFactoy();
    }

    public function oneToolFailsEachTimeDataProvider()
    {
        return [
            'Fails phpcs' => ['phpcs', ['phpcpd', 'phpcbf', 'phpmd', 'parallel-lint', 'phpstan', 'security-checker']],
            'Fails phpcbf' => ['phpcbf', ['phpcs', 'phpcpd', 'phpmd', 'parallel-lint', 'phpstan', 'security-checker']],
            'Fails phpmd' => ['phpmd', ['phpcs', 'phpcbf', 'phpcpd', 'parallel-lint', 'phpstan', 'security-checker']],
            'Fails phpcpd' => ['phpcpd', ['phpcs', 'phpcbf', 'phpmd', 'parallel-lint', 'phpstan', 'security-checker']],
            'Fails parallel-lint' => ['parallel-lint', ['phpcs', 'phpcbf', 'phpmd', 'phpcpd', 'phpstan', 'security-checker']],
            'Fails phpstan' => ['phpstan', ['phpcs', 'phpcbf', 'phpmd', 'parallel-lint', 'phpcpd', 'security-checker']],
            'Fails security-checker' => ['security-checker', ['phpcs', 'phpcbf', 'phpmd', 'parallel-lint', 'phpstan', 'phpcpd']],
        ];
    }

    /**
     * Test added to pairwise testing strategy
     * @test
     */
    function it_returns_empty_errors_when_all_tools_find_NO_errors()
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('security-checker')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpmd')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcs')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcbf')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcpd')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpstan')))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('parallel-lint')))->once();
    }

    /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     */
    function it_returns_errors_when_a_tool_finds_errors($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock);
        $multiProcessExecution->failedToolsByFoundedErrors([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern("%$failedTool fakes an error%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[5])))->once();
    }

    /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     */
    function it_returns_errors_when_a_tool_raise_an_exception($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock);
        $multiProcessExecution->failedToolsByException([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern("%$failedTool fakes an exception%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[5])))->once();
    }

     /**
     * @test
     * @dataProvider oneToolFailsEachTimeDataProvider
     * Edge case explained in the finishExecution method in MultiProcessesExecution.php
     */
    function it_returns_errors_when_a_tool_is_not_succesfully_and_has_errors_in_normal_output_instead_of_errorOutput($failedTool, $successTools)
    {
        $configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock);
        $multiProcessExecution->setFailByFoundedErrorsInNormalOutput([$failedTool]);

        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern("%$failedTool fakes an error in normal output%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[5])))->once();
    }

    public function twoToolsFailsDataProvider()
    {
        return [
            'Fails phpcs' => [
                'Failed tool' => 'phpcs',
                'Failed tool with ignore erros on exit' => 'phpcbf',
                'Way to fail' => 'failedToolsByFoundedErrors',
                'Expected error message (depends on method to fake fail)' => 'fakes an error\n',
            ],
            'Fails phpcbf' => ['phpcbf', 'phpmd', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpmd' => ['phpmd', 'phpcpd', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpcpd' => ['phpcpd', 'parallel-lint', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails parallel-lint' => ['parallel-lint', 'phpstan', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails phpstan' => ['phpstan', 'security-checker', 'failedToolsByFoundedErrors', 'fakes an error\n'],
            'Fails security-checker' => ['security-checker', 'phpcs', 'failedToolsByFoundedErrors', 'fakes an error\n'],
        ];
    }

    /**
     * @test
     * @dataProvider twoToolsFailsDataProvider
     */
    function it_doesnt_set_errors_when_the_tool_finds_errors_but_ignoreErrorsOnExit_flag_is_setted_to_true(
        $failedTool,
        $failedToolWithIgnoreErrosOnExit,
        $methodToFakeFail,
        $expectedErrorMessage
    ) {
        $configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($failedToolWithIgnoreErrosOnExit, ['ignoreErrorsOnExit' => true])
                ->buildArray(),
            self::ALL_TOOLS
        );
        $tools = $this->toolsFactory->__invoke($configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock);
        $multiProcessExecution->$methodToFakeFail([$failedTool, $failedToolWithIgnoreErrosOnExit]);
        $multiProcessExecution->setToolsThatMustFail([$failedTool, $failedToolWithIgnoreErrosOnExit]);


        $errors = $multiProcessExecution->execute($tools, $configurationFile->getProcesses());

        $regExpOfExpectedErrorMessage = "%$failedTool $expectedErrorMessage%";
        $this->assertCount(1, $errors->getErrors());
        $this->assertArrayHasKey($failedTool, $errors->getErrors());
        $this->assertMatchesRegularExpression($regExpOfExpectedErrorMessage, $errors->getErrors()[$failedTool]);


        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern($regExpOfExpectedErrorMessage))->once();

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern(
            $this->messageRegExp($failedToolWithIgnoreErrosOnExit, false)
        ))->once();
        $regExpOfExpectedErrorMessage = "%$failedToolWithIgnoreErrosOnExit $expectedErrorMessage%";
        $printerMock->shouldHaveReceived()->line(
            \Mockery::pattern($regExpOfExpectedErrorMessage)
        )->once();
    }
}
