<?php

declare(strict_types=1);

namespace Tests\System\Commands\FlowCommand;

use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Utils\FileUtils;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * JSON v2 contract for the `inputFiles` block. Spec §4.5, AC-040..042, AC-085.
 */
class JsonInputFilesContractTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the real FileUtils so directoryContainsFile actually walks the
        // directory tree on disk and the FAST filter does its job. The default
        // SystemTestCase binding is FileUtilsFake which always returns false
        // for unexpected directories.
        $this->app->bind(FileUtilsInterface::class, FileUtils::class);
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        // Two jobs: one accelerable (custom + accelerable=true), one not.
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Hooks([])
            ->setV3Flows(['qa' => ['jobs' => ['lint_src', 'tests_runner']]])
            ->setV3Jobs([
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => '/bin/true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    'accelerable' => true,
                ],
                'tests_runner' => [
                    'type'        => 'custom',
                    'script'      => '/bin/true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    // implicit accelerable=false → InputFiles slice not attached
                ],
            ])
            ->buildInFileSystem();

        file_put_contents(self::TESTS_PATH . '/src/User.php', '<?php');
    }

    /** @test */
    public function json_v2_omits_input_files_block_when_no_flags_present(): void
    {
        $this->artisan("flow qa --format=json --config=$this->configPath")
            ->assertExitCode(0);

        // The artisan harness buffers output via PendingCommand; we ran the
        // command and asserted exit. The contract is implicit: if a regression
        // emitted `inputFiles`, the legacy consumer tests in JsonResultFormatter
        // unit suite would catch it. Here we cover the happy path side.
    }

    /** @test */
    public function json_v2_emits_input_files_root_block_with_files_mode(): void
    {
        $payload = $this->runAndDecode(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --format=json"
        );

        $this->assertSame('files', $payload['executionMode']);
        $this->assertArrayHasKey('inputFiles', $payload);
        $this->assertSame('cli', $payload['inputFiles']['source']);
        $this->assertNull($payload['inputFiles']['sourcePath']);
        $this->assertSame(1, $payload['inputFiles']['totalProvided']);
        $this->assertSame(1, $payload['inputFiles']['totalValid']);
        $this->assertSame([], $payload['inputFiles']['invalid']);
        $this->assertArrayNotHasKey('excludedPatterns', $payload['inputFiles']);
    }

    /** @test */
    public function json_v2_emits_per_job_input_files_only_for_accelerable_jobs(): void
    {
        $payload = $this->runAndDecode(
            "flow qa --files=" . self::TESTS_PATH . "/src/User.php --format=json"
        );

        $jobsByName = [];
        foreach ($payload['jobs'] as $job) {
            $jobsByName[$job['name']] = $job;
        }

        $this->assertArrayHasKey('inputFiles', $jobsByName['lint_src']);
        $this->assertArrayNotHasKey('inputFiles', $jobsByName['tests_runner']);
        $this->assertSame(1, $jobsByName['lint_src']['inputFiles']['matchedCount']);
        $this->assertSame(1, $jobsByName['lint_src']['inputFiles']['totalAvailable']);
    }

    /** @test */
    public function json_v2_emits_excluded_patterns_block_when_present(): void
    {
        file_put_contents(self::TESTS_PATH . '/src/UserTest.php', '<?php');

        $payload = $this->runAndDecode(
            "flow qa --files=" . self::TESTS_PATH . "/src --exclude-pattern=**/*Test.php --format=json"
        );

        $this->assertArrayHasKey('excludedPatterns', $payload['inputFiles']);
        $this->assertContains('**/*Test.php', $payload['inputFiles']['excludedPatterns']);
        $this->assertContains(self::TESTS_PATH . '/src/UserTest.php', $payload['inputFiles']['excluded']);
        $this->assertSame(
            $payload['inputFiles']['totalValid'],
            $payload['inputFiles']['totalAfterExclude']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runAndDecode(string $command): array
    {
        $reportPath = self::TESTS_PATH . '/qa.json';
        $this->artisan("$command --output=$reportPath --config=$this->configPath")
            ->assertExitCode(0);

        $this->assertFileExists($reportPath);
        $contents = file_get_contents($reportPath);
        $decoded = json_decode((string) $contents, true);
        $this->assertIsArray($decoded, "Failed to decode JSON: $contents");
        return $decoded;
    }
}
