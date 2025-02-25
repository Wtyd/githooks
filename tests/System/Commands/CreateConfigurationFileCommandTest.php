<?php

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommandTest extends SystemTestCase
{
    /** @test */
    function it_creates_the_configuration_file_in_the_root_of_the_project_using_the_template()
    {
        $templatePath = $this->path . '/vendor/wtyd/githooks/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'githooks.dist.php', '');

        $this->artisan('conf:init')
            ->containsStringInOutput('Configuration file githooks.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'githooks.dist.php', $this->path . '/githooks.php');
    }

    public function configurationFileDataProvider()
    {
        return [
            'The configuration file is in the root directory' => ['githooks.php'],
            'The configuration file is in the qa directory' => ['qa/githooks.php'],
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
            ->containsStringInOutput('githooks configuration file already exists')
            ->assertExitCode(1);
    }

    /** @test */
    function it_prints_an_error_message_when_something_wrong_happens()
    {
        $this->artisan('conf:init')
            ->containsStringInOutput('Failed to copy vendor/wtyd/githooks/qa/githooks.dist.php to githooks.php')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/githooks.php');
    }
}
