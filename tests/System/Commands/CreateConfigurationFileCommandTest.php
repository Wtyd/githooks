<?php

namespace Tests\System\Commands;

// use Illuminate\Support\Facades\Storage;
use phpmock\Mock as PhpmockMock;
use phpmock\MockBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommandTest extends SystemTestCase
{
    /**
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        // $builder->setNamespace('Wtyd\GitHooks\App\Commands')
        $builder->setNamespace('Illuminate\Filesystem')
            ->setName('getcwd')
            ->setFunction(
                function () {
                    return $this->getPath();
                }
            );

        return $builder->build();
    }

    /** @test */
    function it_creates_the_configuration_file_in_the_root_of_the_project_using_the_template()
    {
        $templatePath = $this->path . '/vendor/wtyd/githooks/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'githooks.dist.yml', '');

        // $mock = $this->getMockRootDirectory();
        // $mock->enable();

        $this->artisan('conf:init')
            ->containsStringInOutput('Configuration file githooks.yml has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'githooks.dist.yml', $this->path . '/githooks.yml');

        // $mock->disable();
    }

    public function configurationFileDataProvider()
    {
        return [
            'The configuration file is in the root directory' => ['githooks.yml'],
            'The configuration file is in the qa directory' => ['qa/githooks.yml'],
        ];
    }

    /**
     * @test
     * @dataProvider configurationFileDataProvider
     */
    function it_prints_an_error_message_when_the_configuration_file_already_exists($path)
    {
        Storage::put($path, 'Configuration file contents');

        $this->artisan('conf:init')
            ->containsStringInOutput('githooks.yml configuration file already exists')
            ->assertExitCode(1);
    }

    /** @test */
    function it_prints_an_error_message_when_something_wrong_happens()
    {
        $this->artisan('conf:init')
            ->containsStringInOutput('Failed to copy vendor/wtyd/githooks/qa/githooks.dist.yml to githooks.yml')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/githooks.yml');
    }
}
