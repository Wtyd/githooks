<?php

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommandTest extends SystemTestCase
{
    /** @test */
    function it_creates_the_configuration_file_in_the_root_of_the_project_using_the_template()
    {
        // conf:init looks for qa/githooks.dist.php (local development path)
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'githooks.dist.php', '<?php return [];');

        $this->artisan('conf:init', ['--no-interaction' => true])
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
    function it_prints_an_error_message_when_dist_file_not_found()
    {
        $this->artisan('conf:init', ['--no-interaction' => true])
            ->containsStringInOutput("Distribution file 'githooks.dist.php' not found")
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/githooks.php');
    }

    /** @test */
    function legacy_flag_copies_yaml_dist_file_instead_of_php()
    {
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'githooks.dist.php', '<?php return [];');
        file_put_contents($templatePath . 'githooks.dist.yml', "Tools:\n  - phpstan\n");

        $this->artisan('conf:init', ['--legacy' => true, '--no-interaction' => true])
            ->containsStringInOutput('Configuration file githooks.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'githooks.dist.yml', $this->path . '/githooks.php');
    }

    /** @test */
    function resolves_distribution_file_from_vendor_path_when_available()
    {
        $vendorTemplatePath = $this->path . '/vendor/wtyd/githooks/qa/';
        mkdir($vendorTemplatePath, 0777, true);
        file_put_contents($vendorTemplatePath . 'githooks.dist.php', "<?php return ['source' => 'vendor'];");

        $this->artisan('conf:init', ['--no-interaction' => true])
            ->containsStringInOutput('Configuration file githooks.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($vendorTemplatePath . 'githooks.dist.php', $this->path . '/githooks.php');
    }
}
