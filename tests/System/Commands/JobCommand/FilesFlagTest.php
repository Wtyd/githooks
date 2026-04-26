<?php

declare(strict_types=1);

namespace Tests\System\Commands\JobCommand;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * System-level coverage of `job --files / --files-from / --exclude-pattern`.
 * Spec: spec-design-files-flag.md AC-002, §5.4, §5.8.
 */
class FilesFlagTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->setV3Flows([])
            ->setV3Jobs([
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => '/bin/true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    'accelerable' => true,
                ],
            ])
            ->buildInFileSystem();

        file_put_contents(self::TESTS_PATH . '/src/User.php', '<?php // user');
    }

    /** @test */
    public function exits_with_error_for_mutually_exclusive_flags(): void
    {
        $manifest = self::TESTS_PATH . '/list.txt';
        file_put_contents($manifest, self::TESTS_PATH . "/src/User.php\n");

        $this->artisan(
            "job lint_src --files=" . self::TESTS_PATH . "/src/User.php --files-from=$manifest --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['mutually exclusive'];
    }

    /** @test */
    public function runs_job_with_explicit_file(): void
    {
        $this->artisan(
            "job lint_src --files=" . self::TESTS_PATH . "/src/User.php --config=$this->configPath"
        )->assertExitCode(0);
    }

    /** @test */
    public function exits_with_error_for_all_invalid_paths(): void
    {
        $this->artisan(
            "job lint_src --files=ghost.php --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['all input files are invalid'];
    }

    /** @test */
    public function exits_with_error_for_exclude_pattern_without_input(): void
    {
        $this->artisan(
            "job lint_src --exclude-pattern='**/*Test.php' --config=$this->configPath"
        )->assertExitCode(1);

        $this->containsStringInOutput = ['--exclude-pattern requires --files or --files-from'];
    }
}
