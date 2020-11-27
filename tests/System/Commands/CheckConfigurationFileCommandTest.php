<?php

namespace Tests\System\Commands;

use Illuminate\Console\Application;
use Illuminate\Events\Dispatcher;
use Tests\ConsoleTestCase;
use Tests\System\Utils\ConfigurationFileBuilder;

/**
 * This test is exluded from automated test suite. Only must by runned on pipeline and on isolation. It run all tools and test where is the executable.
 * For it, three scenarios must be considered:
 * 1. All tools are installed globally.
 * 2. All tools are installed how project dependencies.
 * 3. All tools are downloaded as phar in project root path.
 * The test must be run three times, once per scenario. This scenario is configured in pipeline.
 */
class CheckConfigurationFileCommandTest extends ConsoleTestCase
{
    protected $configurationFile;

    protected $artisan;

    // protected function setUp(): void
    // {
    //     $this->deleteDirStructure();

    //     // $this->hiddenConsoleOutput();

    //     $this->createDirStructure();

    //     $events = new Dispatcher($this->container);

    //     $this->artisan = new Application($this->container, $events, 'Version');

    //     $this->configurationFile = new ConfigurationFileBuilder($this->getPath());
    // }

    // protected function tearDown(): void
    // {
    //     $this->deleteDirStructure();
    // }

    //TODO Tests
    //1. Va bien
    //2. Hay errores
    //3. Aunque vaya bien pueden haber warnings

    /** @test */
    function it_pass_all_file_configuration_checks2432()
    {
        $this->markTestIncomplete('no');
        $this->configurationFile->setOptions(['unacosa' => false]);
        // file_put_contents($this->getPath() . '/githooks.yml', $configurationFileBuilder->buildYalm());

        try {
            // $exit = shell_exec('php bin/githooks conf:check');
            $this->artisan('conf:check')
                ->expectsOutput('Your name is Taylor Otwell and you program in PHP.');
            // var_dump($exit);
            // var_dump($exitCode);
            exit;
        } catch (\Throwable $th) {
            //If something goes wrong I avoid throwing the exception because it hides the asserts
        }
    }
}
