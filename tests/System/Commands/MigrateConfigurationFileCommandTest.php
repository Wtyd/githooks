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
}
