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
            ->assertExitCode(0);

        $this->containsStringInOutput = ['Migrated to v3'];
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
            ->assertExitCode(0);

        $this->containsStringInOutput = ['already in v3 format'];
    }

    /** @test */
    public function it_shows_errors_for_empty_config_instead_of_already_v3()
    {
        file_put_contents($this->configPath, '<?php return [];');

        $this->artisan("conf:migrate --config=$this->configPath")
            ->assertExitCode(0);

        // Bug #11 fix: empty config now shows errors instead of "already v3"
        $this->containsStringInOutput = ['has errors', 'jobs'];
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
