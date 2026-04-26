<?php

declare(strict_types=1);

namespace Tests\System\Commands\FlowCommand;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * System-level coverage of `flow --files / --files-from / --exclude-pattern`.
 * Spec: spec-design-files-flag.md §5.1, §5.2, §5.4, §5.7, §5.8.
 */
class FilesFlagTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        // Build a v3 config with one accelerable custom job.
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['lint_src']]])
            ->setV3Jobs([
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => '/bin/true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    'accelerable' => true,
                ],
            ])
            ->buildInFileSystem();

        // A real PHP file the user can target.
        file_put_contents(self::TESTS_PATH . '/src/User.php', '<?php // user');
    }

    /** @test */
    public function exits_with_error_when_files_and_files_from_are_combined(): void
    {
        $manifest = self::TESTS_PATH . '/list.txt';
        file_put_contents($manifest, self::TESTS_PATH . "/src/User.php\n");

        $this->artisan(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --files-from=$manifest --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['mutually exclusive'];
    }

    /** @test */
    public function exits_with_error_when_all_files_are_invalid(): void
    {
        $this->artisan(
            "flow qa --files=ghost1.php,ghost2.php --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['all input files are invalid'];
    }

    /** @test */
    public function exits_with_error_when_exclude_pattern_used_without_input(): void
    {
        $this->artisan(
            "flow qa --exclude-pattern='**/*Test.php' --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['--exclude-pattern requires --files or --files-from'];
    }

    /** @test */
    public function exits_with_error_when_exclude_pattern_eliminates_all(): void
    {
        $this->artisan(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --exclude-pattern='**/*.php' --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['eliminated all input files'];
    }

    /** @test */
    public function exits_with_error_when_files_from_does_not_exist(): void
    {
        $this->artisan(
            "flow qa --files-from=missing.txt --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ["--files-from: file 'missing.txt' does not exist"];
    }

    /** @test */
    public function runs_flow_with_single_explicit_file_in_files_mode(): void
    {
        $this->artisan(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --config=$this->configPath"
        )->assertExitCode(0);

        $this->containsStringInOutput = ['Mode: files'];
    }

    /** @test */
    public function runs_flow_with_files_from_manifest(): void
    {
        $manifest = self::TESTS_PATH . '/list.txt';
        file_put_contents($manifest, "# auto-generated\n" . self::TESTS_PATH . "/src/User.php\n");

        $this->artisan(
            "flow qa --files-from=$manifest --config=$this->configPath"
        )->assertExitCode(0);
    }

    /** @test */
    public function dry_run_with_files_does_not_execute_anything(): void
    {
        $this->artisan(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --dry-run --config=$this->configPath"
        )->assertExitCode(0);
    }
}
