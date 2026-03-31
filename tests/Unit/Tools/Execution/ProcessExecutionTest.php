<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Execution;

use Tests\Mock;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Tests\Doubles\GitStagerFake;
use Tests\Doubles\ProcessExecutionFake;
use Wtyd\GitHooks\Tools\ToolsFactory;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ProcessExecutionTest extends UnitTestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;


    protected $configurationFileBuilder;

    protected $toolsFactory;

    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');

        $this->toolsFactory = new ToolsFactory(new ToolRegistry());
    }

    public function allToolsDataProvider()
    {
        return [
            'phpcs' => ['phpcs'],
            'phpcbf' => ['phpcbf'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
            'parallel-lint' => ['parallel-lint'],
            'phpstan' => ['phpstan'],
            'security-checker' => ['security-checker'],
        ];
    }

    /**
     * @test
     * @dataProvider allToolsDataProvider
     */
    function it_returns_empty_errors_when_the_tool_finds_NO_errors($tool)
    {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), $tool, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $processExecution = new ProcessExecutionFake($printerMock, new GitStagerFake());

        $errors = $processExecution->execute($tools, $this->configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());

        $printerMock->shouldHaveReceived()
            ->line($tools[$tool]->prepareCommand());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp($tool)))->once();
    }

    /**
     * @test
     * @dataProvider allToolsDataProvider
     */
    function it_returns_errors_when_the_tool_finds_errors($tool)
    {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), $tool, new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $processExecution = new ProcessExecutionFake($printerMock, new GitStagerFake());
        $processExecution->setToolsThatMustFail([$tool]);

        $errors = $processExecution->execute($tools, $this->configurationFile->getProcesses());

        $this->assertFalse($errors->isEmpty());
        $printerMock->shouldHaveReceived()
            ->line($tools[$tool]->prepareCommand());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($tool, false)))->once();
    }

    /**
     * @test
     * @dataProvider allToolsDataProvider
     */
    function it_returns_empty_errors_when_the_tool_finds_errors_but_ignoreErrorsOnExit_flag_is_setted_to_true($tool)
    {
        $this->configurationFile = new ConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($tool, ['ignoreErrorsOnExit' => true])
                ->buildArray(),
            $tool,
            new ToolRegistry()
        );

        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);

        $processExecution = new ProcessExecutionFake($printerMock, new GitStagerFake());
        $processExecution->setToolsThatMustFail([$tool]);

        $errors = $processExecution->execute($tools, $this->configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());

        $printerMock->shouldHaveReceived()->resultError(\Mockery::pattern($this->messageRegExp($tool, false)))->once();
    }

    /** @test */
    function it_stages_files_and_reports_success_when_phpcbf_applies_fix()
    {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), 'phpcbf', new ToolRegistry());
        $tools = $this->toolsFactory->__invoke($this->configurationFile->getToolsConfiguration());

        $printerMock = Mock::spy(Printer::class);
        $gitStagerFake = new GitStagerFake();

        $processExecution = new ProcessExecutionFake($printerMock, $gitStagerFake);
        $processExecution->setToolsWithFixApplied(['phpcbf']);

        $errors = $processExecution->execute($tools, $this->configurationFile->getProcesses());

        $this->assertTrue($errors->isEmpty());
        $this->assertTrue($gitStagerFake->wasCalled());

        $printerMock->shouldHaveReceived()->resultSuccess(\Mockery::pattern($this->messageRegExp('phpcbf')))->once();
    }

}
