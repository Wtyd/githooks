<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Execution;

use Tests\Mock;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\Tools\Execution\MultiProcessesExecutionFake;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\Printer;

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

    /** @test */
    function it_returns_empty_errors_when_all_tools_find_NO_errors()
    {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, $tools, $this->configurationFile->getProcesses());

        $errors = $multiProcessExecution->execute();

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
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, $tools, $this->configurationFile->getProcesses());
        $multiProcessExecution->setToolsThatMustFail([$failedTool]);


        $errors = $multiProcessExecution->execute();

        $this->assertFalse($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern("%The tool $failedTool mocks an error%"))->once();

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[0])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[0] mocks an error%"));

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[1])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[1] mocks an error%"));

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[2])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[2] mocks an error%"));

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[3])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[3] mocks an error%"));

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[4])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[4] mocks an error%"));

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($successTools[5])))->once();
        $printerMock->shouldNotHaveReceived()->line(\Mockery::pattern("%The tool $successTools[5] mocks an error%"));
    }

    public function twoToolsFailsDataProvider()
    {
        return [
            'Fails phpcs' => ['phpcs', 'phpcbf'],
            'Fails phpcbf' => ['phpcbf', 'phpmd'],
            'Fails phpmd' => ['phpmd', 'phpcpd'],
            'Fails phpcpd' => ['phpcpd', 'parallel-lint'],
            'Fails parallel-lint' => ['parallel-lint', 'phpstan'],
            'Fails phpstan' => ['phpstan', 'security-checker'],
            'Fails security-checker' => ['security-checker', 'phpcs'],
        ];
    }

    /**
     * @test
     * @dataProvider twoToolsFailsDataProvider
     */
    function it_doesnt_set_errors_when_the_tool_finds_errors_but_ignoreErrorsOnExit_flag_is_setted_to_true(
        $failedTool,
        $failedToolWithIgnoreErrosOnExit
    ) {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder
            ->changeToolOption($failedToolWithIgnoreErrosOnExit, ['ignoreErrorsOnExit' => true])
            ->buildArray(), self::ALL_TOOLS);
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $multiProcessExecution = new MultiProcessesExecutionFake($printerMock, $tools, $this->configurationFile->getProcesses());
        $multiProcessExecution->setToolsThatMustFail([$failedTool, $failedToolWithIgnoreErrosOnExit]);


        $errors = $multiProcessExecution->execute();

        $this->assertCount(1, $errors->getErrors());
        $this->assertArrayHasKey($failedTool, $errors->getErrors());
        $this->assertMatchesRegularExpression("%The tool $failedTool mocks an error%", $errors->getErrors()[$failedTool]);


        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($failedTool, false)))->once();
        $printerMock->shouldHaveReceived()->line(\Mockery::pattern("%The tool $failedTool mocks an error%"))->once();

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern(
            $this->messageRegExp($failedToolWithIgnoreErrosOnExit, false)
        ))->once();
        $printerMock->shouldHaveReceived()->line(
            \Mockery::pattern("%The tool $failedToolWithIgnoreErrosOnExit mocks an error%")
        )->once();
    }
}
