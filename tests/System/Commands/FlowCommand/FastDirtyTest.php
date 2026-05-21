<?php

declare(strict_types=1);

namespace Tests\System\Commands\FlowCommand;

use Tests\Utils\TestCase\SystemTestCase;

/**
 * FEAT-13 wiring of `--fast-dirty` on `flow`:
 *  - mutual exclusion with the other set-defining flags (`--fast`,
 *    `--fast-branch`, `--files`, `--files-from`).
 *
 * Each set-defining flag picks a different file set; combining two has no
 * semantics and must abort with exit code 1.
 *
 * These tests run via `$this->artisan(...)`: they do NOT invoke git so they
 * stay outside `@group git`. The set-computation lives in
 * FileUtilsWorktreeDiffTest.
 */
class FastDirtyTest extends SystemTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = getcwd() . '/' . self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['lint_src']]])
            ->setV3Jobs([
                'lint_src' => [
                    'type'        => 'custom',
                    'script'      => 'true',
                    'paths'       => [self::TESTS_PATH . '/src'],
                    'accelerable' => true,
                ],
            ])
            ->buildInFileSystem();
    }

    /** @test */
    public function exits_with_error_when_fast_dirty_combined_with_fast(): void
    {
        $this->artisan("flow qa --fast-dirty --fast --config=$this->configPath")
            ->expectsOutput('--fast-dirty and --fast are mutually exclusive')
            ->assertExitCode(1);
    }

    /** @test */
    public function exits_with_error_when_fast_dirty_combined_with_fast_branch(): void
    {
        $this->artisan("flow qa --fast-dirty --fast-branch --config=$this->configPath")
            ->expectsOutput('--fast-dirty and --fast-branch are mutually exclusive')
            ->assertExitCode(1);
    }

    /** @test */
    public function exits_with_error_when_fast_dirty_combined_with_files(): void
    {
        $this->artisan(
            "flow qa --fast-dirty --files=" . self::TESTS_PATH . "/src/User.php --config=$this->configPath"
        )
            ->expectsOutput('--fast-dirty and --files are mutually exclusive')
            ->assertExitCode(1);
    }

    /** @test */
    public function exits_with_error_when_fast_dirty_combined_with_files_from(): void
    {
        $manifest = self::TESTS_PATH . '/list.txt';
        file_put_contents($manifest, self::TESTS_PATH . "/src/User.php\n");

        $this->artisan("flow qa --fast-dirty --files-from=$manifest --config=$this->configPath")
            ->expectsOutput('--fast-dirty and --files-from are mutually exclusive')
            ->assertExitCode(1);
    }
}
