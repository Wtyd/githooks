<?php

namespace Tests\System\Commands;

use GitHooks\Configuration;
use Illuminate\Container\Container;
use Tests\Artisan\ConsoleTestCase;
use Tests\FileSystemTrait;
use Tests\Mock;
use Tests\System\Utils\ConfigurationFake;
use Tests\System\Utils\ConfigurationFileBuilder;

class CheckConfigurationFileCommandTest extends ConsoleTestCase
{
    use FileSystemTrait;

    protected $configurationFile;

    protected $artisan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();

        // $this->hiddenConsoleOutput(); //En console tests no sirve

        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());



        // $this->mockConfigurationFile();
        // dd($this->app);
    }

    // protected function tearDown(): void
    // {
    //     parent::tearDown();
    //     $this->deleteDirStructure();
    // }

    //TODO Tests
    //1. Va bien
    //2. Hay errores
    //3. Aunque vaya bien pueden haber warnings

    /** @test */
    function it_pass_all_file_configuration_checks2432()
    {
        var_dump("\n======================TESTS=========================");
        $this->container = Container::getInstance();
        // $this->container = Container::getInstance();
        // dd($this->path);
        $mockConfiguration = Mock::mock(Configuration::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mockConfiguration->shouldReceive('findConfigurationFile')->andReturn($this->getPath() . '/githooks.yml');


        // $this->container->instance(Configuration::class, ConfigurationFake::class, true);


        $this->app->bind(Configuration::class, ConfigurationFake::class, false);
        // dd($this->app->make(Configuration::class));

        // $mock = $this->partialMock(Configuration::class, function ($mock) {
        //     $mock->shouldAllowMockingProtectedMethods();
        //     $mock->shouldReceive('findConfigurationFile')->andReturn($this->getPath() . '/githooks.yml');
        // });

        // $mock = $this->mock(Configuration::class, function ($mock) {
        //     return new ConfigurationFake();
        // });

        // dd(Container::getInstance());
        $this->configurationFile->setOptions(['unacosa' => false]);
        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        $this->artisan('conf:check')
            ->containsStringInOutput("The key 'unacosa' is not a valid option")
            // ->showOutput();
            ->containsStringInOutput("Checking the configuration file:\n")
            ->containsStringInOutput('The file githooks.yml has the correct format.');
    }
}
