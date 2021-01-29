<?php

namespace Tests\System\Commands;

use phpmock\MockBuilder;
use phpmock\Mock as PhpmockMock;
use Tests\Artisan\ConsoleTestCase;

class CreateConfigurationFileCommandTest extends ConsoleTestCase
{

    protected $configurationFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteDirStructure();
    }

    protected function tearDown(): void
    {
        $this->deleteDirStructure();
    }

    /**
     * @return PhpmockMock
     */
    public function getMockRootDirectory(): PhpmockMock
    {
        $builder = new MockBuilder();
        $builder->setNamespace('GitHooks\Commands')
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

        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan('conf:init')
            ->containsStringInOutput('Configuration file githooks.yml has been created in root path');

        $this->assertFileEquals($templatePath . 'githooks.dist.yml', $this->path . '/githooks.yml');

        $mock->disable();
    }

    /** @test */
    function it_prints_an_error_message_when_something_wrong_happens()
    {
        $mock = $this->getMockRootDirectory();
        $mock->enable();

        $this->artisan('conf:init')
            ->containsStringInOutput('Failed to copy ' . $this->path . '/vendor/wtyd/githooks/qa/githooks.dist.yml' . ' to ' . $this->path . '/githooks.yml');

        $assertFileDoesNotExist = $this->assertFileDoesNotExist;
        $this->$assertFileDoesNotExist($this->path . '/githooks.yml');
        // $this->assertFileDoesNotExist($this->path . '/githooks.yml');

        $mock->disable();
    }
}
