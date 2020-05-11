<?php

namespace Tests\System;

use GitHooks\GitHooks;
use GitHooks\LoadTools\SmartStrategy;
use GitHooks\Tools\CheckSecurity;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Mockery;
use Tests\SystemTestCase;

class SmartStrategySystemTest extends SystemTestCase
{
    // use InteractsWithContainer;

    //     Check Security   Code Sniffer    CPDectector Mess Detector   Parallellint    Stan
    // 1        OK              OK              OK          OK              OK          OK
    // 2        OK              KO              KO          KO              KO          KO
    // 3        OK              exclude         exclude     exclude         exclude     exclude
    // 4        KO              KO              exclude     OK              KO          exclude
    // 5        KO              exclude         OK          KO              exclude     OK
    // 6        KO              OK              KO          exclude         OK          KO
    // 7                        exclude         KO          OK              exclude     KO
    // 8                        OK              exclude     KO              OK          exclude
    // 9                        KO              OK          exclude         KO          OK

    protected $configurationFile;

    protected function setUp(): void
    {
        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();
        $this->createDirStructure();

        $this->configurationFile = new ConfigurationFileBuilder($this->getPath());
        $this->configurationFile->setOptions(['smartExecution' => true]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /** @test */
    function it_execute_all_tools_and_pass_all_checks_with_smartStrategy()
    {
        $fileBuilder = new PhpFileBuilder('File');

        // $configurationFileBuilder = new ConfigurationFileBuilder($this->getPath());

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFile->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        // $checkSecurityMock = $this->overload(CheckSecurity::class)->makePartial();
        // $checkSecurityMock->shouldReceive('execute')->once();

        // $containerMock = Mockery::mock('Illuminate\Container\Container[make]')->makePartial();
        // $containerMock->shouldReceive('make')->with('check-security')->twice()->andReturn(new CheckSecurityFakeOk());

        $container = Container::getInstance();
        $container->bind(CheckSecurity::class, CheckSecurityFakeOk::class);

        // $smartStrategyMock = $this->overload(SmartStrategy::class)->shouldAllowMockingProtectedMethods()->makePartial();
        // $smartStrategyMock = Mockery::mock(SmartStrategy::class)->shouldAllowMockingProtectedMethods()->makePartial();
        // $smartStrategyMock->shouldNotReceive('toolShouldSkip')->with('phpmd')->andReturn(true);


        // $container->partialMock(SmartStrategy::class, function ($mock) {
        //     $mock->shouldNotReceive('toolShouldSkip')->with('phpmd')->andReturn(true);
        // });

        $githooks = $container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            //Si algo sale mal evito lanzar la excepcion porque oculta los asserts
        }
        //TODO tengo que doblar el acceso a los ficheros modificados de git
        $regExp = '(\.phar)? - OK\. Time: \d+\.\d{2}'; //phpcbf[.phar] - OK. Time: 0.18
        $this->assertRegExp("%phpcbf$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpmd$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpcpd$regExp%", $this->getActualOutput());
        $this->assertRegExp("%phpstan$regExp%", $this->getActualOutput());
        $this->assertRegExp("%parallel-lint$regExp%", $this->getActualOutput());
        $this->assertRegExp("%check-security$regExp%", $this->getActualOutput());
        $this->assertRegExp('%Tiempo total de ejecución = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Tus cambios se han commiteado.', $this->getActualOutput());
    }

    public function overload(string $class)
    {
        return Mockery::mock(sprintf('overload:%s', $class));
    }
}
