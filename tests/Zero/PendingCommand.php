<?php

namespace Tests\Zero;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Mockery;
use Mockery\Exception\NoMatchingExpectationException;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Illuminate\Testing\PendingCommand adaptation (https://github.com/laravel/framework/blob/8.x/src/Illuminate/Testing/PendingCommand.php)
 */
class PendingCommand
{
    /**
     * The test being run.
     *
     * @var \Illuminate\Foundation\Testing\TestCase
     */
    public $test;

    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The command to run.
     *
     * @var string
     */
    protected $command;

    /**
     * The parameters to pass to the command.
     *
     * @var array
     */
    protected $parameters;

    /**
     * The expected exit code.
     *
     * @var int
     */
    protected $expectedExitCode;

    /**
     * Determine if command has executed.
     *
     * @var bool
     */
    protected $hasExecuted = false;

    protected $showOutput = false;

    /**
     * Create a new pending console command run.
     *
     * @param  \PHPUnit\Framework\TestCase  $test
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  string  $command
     * @param  array  $parameters
     * @return void
     */
    public function __construct(PHPUnitTestCase $test, \Illuminate\Contracts\Foundation\Application $app, $command, $parameters)
    {
        $this->app = $app;
        $this->test = $test;
        $this->command = $command;
        $this->parameters = $parameters;
    }

    /**
     * Specify an expected question that will be asked when the command runs.
     *
     * @param  string  $question
     * @param  string|bool  $answer
     * @return $this
     */
    public function expectsQuestion($question, $answer)
    {
        $this->test->expectedQuestions[] = [$question, $answer];

        return $this;
    }

    /**
     * Specify an expected confirmation question that will be asked when the command runs.
     *
     * @param  string  $question
     * @param  string  $answer
     * @return $this
     */
    public function expectsConfirmation($question, $answer = 'no')
    {
        return $this->expectsQuestion($question, strtolower($answer) === 'yes');
    }

    /**
     * Specify an expected choice question with expected answers that will be asked/shown when the command runs.
     *
     * @param  string  $question
     * @param  string|array  $answer
     * @param  array  $answers
     * @param  bool  $strict
     * @return $this
     */
    public function expectsChoice($question, $answer, $answers, $strict = false)
    {
        $this->test->expectedChoices[$question] = [
            'expected' => $answers,
            'strict' => $strict,
        ];

        return $this->expectsQuestion($question, $answer);
    }

    /**
     * Specify output that should be printed when the command runs.
     *
     * @param  string  $output
     * @return $this
     */
    public function expectsOutput($output)
    {
        $this->test->expectedOutput[] = $output;

        return $this;
    }

    /**
     * Specify output that should never be printed when the command runs.
     *
     * @param  string  $output
     * @return $this
     */
    public function doesntExpectOutput($output)
    {
        $this->test->unexpectedOutput[$output] = false;

        return $this;
    }

    /**
     * Specify output that should be printed when the command runs.
     *
     * @param  string  $stringInOutput
     * @return $this
     */
    public function containsStringInOutput($stringInOutput)
    {
        $this->test->containsStringInOutput[] = $stringInOutput;

        return $this;
    }

    /**
     * Specify output that not should be printed when the command runs.
     *
     * @param  string  $string
     * @return $this
     */
    public function notContainsStringInOutput($string)
    {
        $this->test->notContainsStringInOutput[] = $string;

        return $this;
    }

    /**
     * Specify output that should be printed when the command runs.
     *
     * @param  string  $stringInOutput
     * @return $this
     */
    public function matchesRegularExpression($matchesRegularExpression)
    {
        $this->test->matchesRegularExpression[] = $matchesRegularExpression;

        return $this;
    }

    /**
     * Specify output that not should be printed when the command runs.
     *
     * @param  string  $stringInOutput
     * @return $this
     */
    public function notMatchesRegularExpression($notMatchesRegularExpression)
    {
        $this->test->notMatchesRegularExpression[] = $notMatchesRegularExpression;

        return $this;
    }

    /**
     * Checks if tool has been executed successfully
     *
     * @param  string  $tool
     * @return $this
     */
    public function toolHasBeenExecutedSuccessfully($tool)
    {
        $this->test->toolHasBeenExecutedSuccessfully[] = $tool;

        return $this;
    }

    /**
     * Checks if tool has failed
     *
     * @param  string  $tool
     * @return $this
     */
    public function toolHasFailed($tool)
    {
        $this->test->toolHasFailed[] = $tool;

        return $this;
    }

    /**
     * Checks if tool didn't run
     *
     * @param  string  $tool
     * @return $this
     */
    public function toolDidNotRun($tool)
    {
        $this->test->toolDidNotRun[] = $tool;

        return $this;
    }

     /**
     * Specify a table that should be printed when the command runs.
     *
     * @param  array  $headers
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $rows
     * @param  string  $tableStyle
     * @param  array  $columnStyles
     * @return $this
     */
    public function expectsTable($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $table = (new Table($output = new BufferedOutput()))
            ->setHeaders((array) $headers)
            ->setRows($rows instanceof Arrayable ? $rows->toArray() : $rows)
            ->setStyle($tableStyle);

        foreach ($columnStyles as $columnIndex => $columnStyle) {
            $table->setColumnStyle($columnIndex, $columnStyle);
        }

        $table->render();

        $lines = array_filter(
            explode(PHP_EOL, $output->fetch())
        );

        foreach ($lines as $line) {
            $this->expectsOutput($line);
        }

        return $this;
    }

    /**
     * Print the output. By default, unlike in Laravel, the output is hidden. In this way, when executing the test suite, messages are not printed by
     * the console, leaving a cleaner log. This method activates screen output so that you can trace the flow of commands while the test is developing.
     * Finally, this method must be removed from the test once it is finished.
     *
     * @return $this
     */
    public function showOutput()
    {
        $this->showOutput = true;

        return $this;
    }

    /**
     * Assert that the command has the given exit code.
     *
     * @param  int  $exitCode
     * @return $this
     */
    public function assertExitCode($exitCode)
    {
        $this->expectedExitCode = $exitCode;

        return $this;
    }

    /**
     * Execute the command.
     *
     * @return int
     */
    public function execute()
    {
        return $this->run();
    }

    /**
     * Execute the command.
     *
     * @return int
     *
     * @throws \Mockery\Exception\NoMatchingExpectationException
     */
    public function run()
    {
        $this->hasExecuted = true;

        $mock = $this->mockConsoleOutput();

        try {
            $this->OutputShouldBeShown();

            $exitCode = $this->app->make(Kernel::class)->call($this->command, $this->parameters, $mock);
        } catch (NoMatchingExpectationException $e) {
            if ($e->getMethodName() === 'askQuestion') {
                $this->test->fail('Unexpected question "' . $e->getActualArguments()[0]->getQuestion() . '" was asked.');
            }

            throw $e;
        }

        if ($this->expectedExitCode !== null) {
            $this->test->assertEquals(
                $this->expectedExitCode,
                $exitCode,
                "Expected status code {$this->expectedExitCode} but received {$exitCode}."
            );
        }

        $this->verifyExpectations();
        $this->flushExpectations();

        return $exitCode;
    }

    /**
     * By default, console output is hidden. If $showOutput is true output is shown, else is hidden.
     * See  $this->showOutput() method.
     *
     * @return void
     */
    public function outputShouldBeShown()
    {
        if (!$this->showOutput) {
            $this->test->setOutputCallback(function () {
            });
        }
    }

    /**
     * Determine if expected questions / choices / outputs are fulfilled.
     *
     * @return void
     */
    protected function verifyExpectations()
    {
        if (count($this->test->expectedQuestions)) {
            $this->test->fail('Question "' . Arr::first($this->test->expectedQuestions)[0] . '" was not asked.');
        }

        if (count($this->test->expectedChoices) > 0) {
            foreach ($this->test->expectedChoices as $question => $answers) {
                $assertion = $answers['strict'] ? 'assertEquals' : 'assertEqualsCanonicalizing';

                $this->test->{$assertion}(
                    $answers['expected'],
                    $answers['actual'],
                    'Question "' . $question . '" has different options.'
                );
            }
        }

        // if (count($this->test->expectedOutput)) {
        //     // dd($this->test->expectedOutput);
        //     $this->test->assertEquals(
        //         $this->test->expectedOutput,
        //         $this->test->getActualOutput(),
        //         'Output "' . Arr::first($this->test->expectedOutput) . '" was not printed.'
        //     );
        // }
        if (count($this->test->expectedOutput)) {
            $this->test->fail('Output "' . Arr::first($this->test->expectedOutput) . '" was not printed.');
        }


        if (count($this->test->containsStringInOutput)) {
            foreach ($this->test->containsStringInOutput as $key => $string) {
                $this->test->assertStringContainsString(
                    $string,
                    $this->test->getActualOutput(),
                    'Output "' . $string . '" was not printed.'
                );
                unset($this->test->containsStringInOutput[$key]);
            }
        }

        if (count($this->test->notContainsStringInOutput)) {
            foreach ($this->test->notContainsStringInOutput as $key => $string) {
                $this->test->assertStringNotContainsString(
                    $string,
                    $this->test->getActualOutput(),
                    'Output "' . $string . '" was printed.'
                );
                unset($this->test->containsStringInOutput[$key]);
            }
        }

        if (count($this->test->matchesRegularExpression)) {
            foreach ($this->test->matchesRegularExpression as $key => $pattern) {
                $this->test->assertMatchesRegularExpression(
                    $pattern,
                    $this->test->getActualOutput(),
                    'Output "' . $this->test->getActualOutput() . '" does not match with regular expression given:' . $pattern
                );
                unset($this->test->matchesRegularExpression[$key]);
            }
        }

        if (count($this->test->matchesRegularExpression)) {
            foreach ($this->test->matchesRegularExpression as $key => $pattern) {
                $this->test->assertMatchesRegularExpression(
                    $pattern,
                    $this->test->getActualOutput(),
                    'Output "' . $this->test->getActualOutput() . '" match with regular expression given:' . $pattern
                );
                unset($this->test->matchesRegularExpression[$key]);
            }
        }

        if (count($this->test->toolHasBeenExecutedSuccessfully)) {
            foreach ($this->test->toolHasBeenExecutedSuccessfully as $key => $tool) {
                $this->test->assertMatchesRegularExpression(
                    "%$tool(\.phar)? - OK\. Time: \d+\.\d{2}%",
                    $this->test->getActualOutput(),
                    "The tool $tool has not been executed successfully"
                );
                unset($this->test->toolHasBeenExecutedSuccessfully[$key]);
            }
        }

        if (count($this->test->toolHasFailed)) {
            foreach ($this->test->toolHasFailed as $key => $tool) {
                $this->test->assertMatchesRegularExpression(
                    "%$tool(\.phar)? - KO\. Time: \d+\.\d{2}%",
                    $this->test->getActualOutput(),
                    "The tool $tool has not failed"
                );
                unset($this->test->toolHasBeenExecutedSuccessfully[$key]);
            }
        }

        if (count($this->test->toolDidNotRun)) {
            foreach ($this->test->toolDidNotRun as $key => $tool) {
                $this->test->assertStringNotContainsString(
                    $tool,
                    $this->test->getActualOutput(),
                    "The tool $tool has been run"
                );
                unset($this->test->containsStringInOutput[$key]);
            }
        }
    }

    /**
     * Mock the application's console output.
     *
     * @return \Mockery\MockInterface
     */
    protected function mockConsoleOutput()
    {
        $mock = Mockery::mock(OutputStyle::class . '[askQuestion]', [
            (new ArrayInput($this->parameters)), $this->createABufferedOutputMock(),
        ]);

        foreach ($this->test->expectedQuestions as $i => $question) {
            $mock->shouldReceive('askQuestion')
                ->once()
                ->ordered()
                ->with(Mockery::on(function ($argument) use ($question) {
                    if (isset($this->test->expectedChoices[$question[0]])) {
                        $this->test->expectedChoices[$question[0]]['actual'] = $argument->getAutocompleterValues();
                    }

                    return $argument->getQuestion() == $question[0];
                }))
                ->andReturnUsing(function () use ($question, $i) {
                    unset($this->test->expectedQuestions[$i]);

                    return $question[1];
                });
        }

        $this->app->bind(OutputStyle::class, function () use ($mock) {
            return $mock;
        });

        return $mock;
    }

    /**
     * Create a mock for the buffered output.
     *
     * @return \Mockery\MockInterface
     */
    private function createABufferedOutputMock()
    {
        $mock = Mockery::mock(BufferedOutput::class . '[doWrite]')
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();

        $this->applyTableOutputExpectations($mock);
        foreach ($this->test->expectedOutput as $i => $output) {
            $mock->shouldReceive('doWrite')
                ->once()
                ->ordered()
                ->with($output, Mockery::any())
                ->andReturnUsing(function () use ($i) {
                    unset($this->test->expectedOutput[$i]);
                });
        }

        foreach ($this->test->unexpectedOutput as $output => $displayed) {
            $mock->shouldReceive('doWrite')
                ->once()
                ->ordered()
                ->with($output, Mockery::any())
                ->andReturnUsing(function () use ($output) {
                    $this->test->unexpectedOutput[$output] = true;
                });
        }

        return $mock;
    }

    /**
     * Apply the output table expectations to the mock.
     *
     * @param  \Mockery\MockInterface  $mock
     * @return void
     */
    private function applyTableOutputExpectations($mock)
    {
        foreach ($this->test->expectedTables as $i => $consoleTable) {
            $table = (new Table($output = new BufferedOutput()))
                ->setHeaders($consoleTable['headers'])
                ->setRows($consoleTable['rows'])
                ->setStyle($consoleTable['tableStyle']);

            foreach ($consoleTable['columnStyles'] as $columnIndex => $columnStyle) {
                $table->setColumnStyle($columnIndex, $columnStyle);
            }

            $table->render();

            $lines = array_filter(
                explode(PHP_EOL, $output->fetch())
            );

            foreach ($lines as $line) {
                $this->expectsOutput($line);
            }

            unset($this->test->expectedTables[$i]);
        }
    }

    /**
     * Flush the expectations from the test case.
     *
     * @return void
     */
    protected function flushExpectations()
    {
        $this->test->expectedOutput = [];
        $this->test->unexpectedOutput = [];
        $this->test->expectedTables = [];
        $this->test->expectedQuestions = [];
        $this->test->expectedChoices = [];
        $this->test->containsStringInOutput = [];
        $this->test->notContainsStringInOutput = [];
        $this->test->matchesRegularExpression = [];
        $this->test->notMatchesRegularExpression = [];
        $this->test->toolHasBeenExecutedSuccessfully = [];
        $this->test->toolHasFailed = [];
        $this->test->toolDidNotRun = [];
    }

    /**
     * Handle the object's destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->hasExecuted) {
            return;
        }

        $this->run();
    }
}
