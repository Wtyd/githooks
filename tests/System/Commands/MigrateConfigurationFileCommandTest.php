<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;

class MigrateConfigurationFileCommandTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
    }

    /** @test */
    public function it_migrates_v2_config_to_v3()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Migrated to v3');
    }

    /** @test */
    public function it_creates_backup_file()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileExists($this->configPath . '.v2.bak');
    }

    /** @test */
    public function it_reports_already_v3_format()
    {
        $this->configurationFileBuilder->enableV3Mode()->buildInFileSystem();

        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('already in v3 format');
    }

    /** @test */
    public function it_shows_errors_for_empty_config_instead_of_already_v3()
    {
        file_put_contents($this->configPath, '<?php return [];');

        // Bug #11 fix: empty config now shows errors instead of "already v3"
        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0)
            ->containsStringInOutput('has errors')
            ->containsStringInOutput('jobs');
    }

    /** @test */
    public function migrates_yaml_legacy_config_and_removes_original_yaml_file()
    {
        $yamlPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.yml';
        $migratedPhpPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->setName('githooks.yml');
        file_put_contents($yamlPath, $legacyBuilder->buildYaml());

        $this->artisan("conf:migrate --config=$yamlPath")
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($yamlPath);
        $this->assertFileExists($migratedPhpPath);
        $this->assertFileExists($yamlPath . '.v2.bak');
    }

    /** @test */
    public function preserves_php_source_path_when_migrating_from_php_legacy_config()
    {
        $legacyBuilder = new ConfigurationFileBuilder(self::TESTS_PATH);
        $legacyBuilder->buildInFileSystem();

        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileExists($this->configPath);
        $this->assertStringContainsString("'hooks'", file_get_contents($this->configPath));
    }

    /**
     * The migration is only done when its result is usable. Reporting
     * "Migrated to v3" and exiting 0 over a file that every later command
     * rejects sends the user off with a broken configuration and no signal.
     *
     * @test
     */
    public function it_fails_when_the_generated_v3_config_is_not_valid()
    {
        $legacyPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        file_put_contents(
            $legacyPath,
            "<?php\nreturn ['Tools' => ['not-a-real-tool'], 'not-a-real-tool' => ['paths' => ['src']]];\n"
        );

        $this->artisan("conf:migrate --config=$legacyPath")
            ->assertExitCode(1)
            ->containsStringInOutput('is not a supported tool');

        // The backup survives so the original is recoverable.
        $this->assertFileExists($legacyPath . '.v2.bak');
    }

    /** @test A named script tool migrates into a runnable custom job, not an unsupported type */
    public function it_migrates_a_named_script_tool_into_a_valid_v3_config()
    {
        $legacyPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';
        file_put_contents($legacyPath, "<?php\nreturn " . var_export([
            'Tools' => ['my-script'],
            'script' => [
                'name' => 'my-script',
                'executablePath' => 'echo',
                'otherArguments' => 'Script tool works!',
            ],
        ], true) . ";\n");

        $this->artisan("conf:migrate --config=$legacyPath")
            ->assertExitCode(0)
            ->containsStringInOutput('Migrated to v3');

        $migrated = strval(file_get_contents($legacyPath));
        $this->assertStringContainsString("'type' => 'custom'", $migrated);
        $this->assertStringContainsString("'script' => 'echo Script tool works!'", $migrated);
    }

    /** @test */
    public function handles_parser_exception_and_exits_with_error_code()
    {
        $yamlPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.yml';
        file_put_contents($yamlPath, "Tools:\n  - phpstan\n  invalid: [not closed\n");

        $this->artisan("conf:migrate --config=$yamlPath")
            ->assertExitCode(1);

        $this->assertFileExists($yamlPath);
        $this->assertFileDoesNotExist($yamlPath . '.v2.bak');
    }
}
