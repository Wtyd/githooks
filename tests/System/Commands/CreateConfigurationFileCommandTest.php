<?php

namespace Tests\System\Commands;

use Tests\Doubles\ToolDetectorFake;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\Alfil\Configuration\ToolDetector;
use Wtyd\Alfil\Utils\Storage;

class CreateConfigurationFileCommandTest extends SystemTestCase
{
    /** @test */
    function it_creates_the_configuration_file_in_the_root_of_the_project_using_the_template()
    {
        // conf:init looks for qa/alfil.dist.php (local development path)
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'alfil.dist.php', '<?php return [];');

        $this->artisan('conf:init', ['--no-interaction' => true])
            ->containsStringInOutput('Configuration file alfil.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'alfil.dist.php', $this->path . '/alfil.php');
    }

    public function configurationFileDataProvider()
    {
        return [
            'alfil.php in the root directory' => ['alfil.php'],
            'alfil.php in the qa directory' => ['qa/alfil.php'],
            'pre-4.0 githooks.php in the root directory' => ['githooks.php'],
            'pre-4.0 githooks.php in the qa directory' => ['qa/githooks.php'],
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
            ->containsStringInOutput('Alfil configuration file already exists')
            ->assertExitCode(1);
    }

    /** @test */
    function it_appends_the_history_dir_to_gitignore_when_creating_config()
    {
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'alfil.dist.php', '<?php return [];');

        $this->artisan('conf:init', ['--no-interaction' => true])->assertExitCode(0);

        $this->assertStringContainsString('.githooks/history/', file_get_contents($this->path . '/.gitignore'));
    }

    /** @test */
    function it_does_not_duplicate_the_history_dir_in_an_existing_gitignore()
    {
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'alfil.dist.php', '<?php return [];');
        Storage::put('.gitignore', "vendor/\n.githooks/history/\n");

        $this->artisan('conf:init', ['--no-interaction' => true])->assertExitCode(0);

        $contents = file_get_contents($this->path . '/.gitignore');
        $this->assertSame(1, substr_count($contents, '.githooks/history/'));
    }

    /** @test */
    function it_prints_an_error_message_when_dist_file_not_found()
    {
        $this->artisan('conf:init', ['--no-interaction' => true])
            ->containsStringInOutput("Distribution file 'alfil.dist.php' not found")
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path . '/alfil.php');
    }

    /** @test */
    function resolves_distribution_file_from_vendor_path_when_available()
    {
        $vendorTemplatePath = $this->path . '/vendor/wtyd/alfil/qa/';
        mkdir($vendorTemplatePath, 0777, true);
        file_put_contents($vendorTemplatePath . 'alfil.dist.php', "<?php return ['source' => 'vendor'];");

        $this->artisan('conf:init', ['--no-interaction' => true])
            ->containsStringInOutput('Configuration file alfil.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($vendorTemplatePath . 'alfil.dist.php', $this->path . '/alfil.php');
    }

    /** @test */
    function interactive_mode_falls_back_to_template_when_no_qa_tools_detected()
    {
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'alfil.dist.php', '<?php return [];');

        // ToolDetectorFake defaults to empty detected list
        $this->artisan('conf:init')
            ->containsStringInOutput('Configuration file alfil.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'alfil.dist.php', $this->path . '/alfil.php');
    }

    /** @test */
    function interactive_mode_falls_back_to_template_when_user_deselects_every_tool()
    {
        $templatePath = $this->path . '/qa/';
        mkdir($templatePath, 0777, true);
        file_put_contents($templatePath . 'alfil.dist.php', '<?php return [];');

        /** @var ToolDetectorFake $detector */
        $detector = $this->app->make(ToolDetector::class);
        $detector->setDetected(['phpstan']);

        $this->artisan('conf:init')
            ->expectsConfirmation('  Include phpstan?', 'no')
            ->containsStringInOutput('Configuration file alfil.php has been created in root path')
            ->assertExitCode(0);

        $this->assertFileEquals($templatePath . 'alfil.dist.php', $this->path . '/alfil.php');
    }

    /** @test */
    function interactive_mode_generates_config_with_selected_tools_for_pre_commit_hook()
    {
        /** @var ToolDetectorFake $detector */
        $detector = $this->app->make(ToolDetector::class);
        $detector->setDetected(['phpstan', 'phpcs']);

        $this->artisan('conf:init')
            ->expectsConfirmation('  Include phpstan?', 'yes')
            ->expectsConfirmation('  Include phpcs?', 'yes')
            ->expectsQuestion('Source directories (comma-separated)', 'src')
            ->expectsChoice(
                'Which hook events to configure?',
                'pre-commit',
                ['pre-commit', 'pre-push', 'both', 'none']
            )
            ->containsStringInOutput('Configuration file alfil.php created with 2 tool(s)')
            ->assertExitCode(0);

        $generated = file_get_contents($this->path . '/alfil.php');
        $this->assertStringContainsString('phpstan', $generated);
        $this->assertStringContainsString('phpcs', $generated);
        $this->assertStringContainsString('pre-commit', $generated);
    }

    /** @test */
    function interactive_mode_configures_pre_push_hook_when_choice_is_pre_push()
    {
        /** @var ToolDetectorFake $detector */
        $detector = $this->app->make(ToolDetector::class);
        $detector->setDetected(['phpstan']);

        $this->artisan('conf:init')
            ->expectsConfirmation('  Include phpstan?', 'yes')
            ->expectsQuestion('Source directories (comma-separated)', 'src')
            ->expectsChoice(
                'Which hook events to configure?',
                'pre-push',
                ['pre-commit', 'pre-push', 'both', 'none']
            )
            ->assertExitCode(0);

        $this->assertStringContainsString('pre-push', file_get_contents($this->path . '/alfil.php'));
    }

    /** @test */
    function interactive_mode_configures_both_hooks_when_choice_is_both()
    {
        /** @var ToolDetectorFake $detector */
        $detector = $this->app->make(ToolDetector::class);
        $detector->setDetected(['phpstan']);

        $this->artisan('conf:init')
            ->expectsConfirmation('  Include phpstan?', 'yes')
            ->expectsQuestion('Source directories (comma-separated)', 'src')
            ->expectsChoice(
                'Which hook events to configure?',
                'both',
                ['pre-commit', 'pre-push', 'both', 'none']
            )
            ->assertExitCode(0);

        $generated = file_get_contents($this->path . '/alfil.php');
        $this->assertStringContainsString('pre-commit', $generated);
        $this->assertStringContainsString('pre-push', $generated);
    }

    /** @test */
    function interactive_mode_configures_no_hook_events_when_choice_is_none()
    {
        /** @var ToolDetectorFake $detector */
        $detector = $this->app->make(ToolDetector::class);
        $detector->setDetected(['phpstan']);

        $this->artisan('conf:init')
            ->expectsConfirmation('  Include phpstan?', 'yes')
            ->expectsQuestion('Source directories (comma-separated)', 'src')
            ->expectsChoice(
                'Which hook events to configure?',
                'none',
                ['pre-commit', 'pre-push', 'both', 'none']
            )
            ->assertExitCode(0);

        $this->assertFileExists($this->path . '/alfil.php');
    }
}
