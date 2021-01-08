<?php

namespace Tests\Artisan;

// namespace Illuminate\Foundation\Testing\Concerns;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;

/**
 * Illuminate\Foundation\Testing\Concerns\InteractsWithConsole adaptation
 */
trait InteractsWithConsole
{
    /**
     * Indicates if the console output should be mocked.
     *
     * @var bool
     */
    public $mockConsoleOutput = true;

    /**
     * All of the expected output lines.
     *
     * @var array
     */
    public $expectedOutput = [];

    /**
     * All of the output lines that aren't expected to be displayed.
     *
     * @var array
     */
    public $unexpectedOutput = [];


    /**
     * All of the expected ouput tables.
     *
     * @var array
     */
    public $expectedTables = [];

    /**
     * The string is contained in the output.
     *
     * @var array
     */
    public $containsStringInOutput = [];

    /**
     * The string is contained in the output.
     *
     * @var array
     */
    public $notContainsStringInOutput = [];

    /**
     * All of the expected questions.
     *
     * @var array
     */
    public $expectedQuestions = [];

    /**
     * All of the expected choice questions.
     *
     * @var array
     */
    public $expectedChoices = [];

    /**
     * Call artisan command and return code.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \Illuminate\Testing\PendingCommand|int
     */
    public function artisan($command, $parameters = [])
    {
        if (!$this->mockConsoleOutput) {
            return $this->app[Kernel::class]->call($command, $parameters);
        }

        // $this->beforeApplicationDestroyed(function () { //Todo esto ya no está en la ultima version
        //     if (count($this->expectedQuestions)) {
        //         $this->fail('Question "' . Arr::first($this->expectedQuestions)[0] . '" was not asked.');
        //     }

        //     if (count($this->expectedOutput)) {
        //         $this->fail('Output "' . Arr::first($this->expectedOutput) . '" was not printed.');
        //     }
        // });

        return new PendingCommand($this, $this->app, $command, $parameters);
    }

    /**
     * Disable mocking the console output.
     *
     * @return $this
     */
    protected function withoutMockingConsoleOutput()
    {
        $this->mockConsoleOutput = false;

        $this->app->offsetUnset(OutputStyle::class);

        return $this;
    }
}
