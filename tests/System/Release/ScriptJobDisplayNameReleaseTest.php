<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release test for BUG-23: `ScriptJob::getDisplayName()` used to return
 * `$this->executable` instead of `$this->name`, making two parallel `script`-typed
 * jobs with the same executable indistinguishable in the OK/KO/SKIP output of
 * the executor. After the fix every Job type (including `script`) returns its
 * config key — the override in `src/Jobs/ScriptJob.php` is gone.
 *
 * Required as @group release because the consumer (`Wtyd\GitHooks\Execution\JobExecutor`)
 * lives inside the `.phar`; an asserted regression here means the fix is embedded
 * in the bundled binary.
 *
 * @group release
 */
class ScriptJobDisplayNameReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function job_output_shows_job_name_not_executable_path_for_script_type(): void
    {
        $this->configurationFileBuilder
            ->setV3Jobs([
                'shard_alpha' => [
                    'type'            => 'script',
                    'executable-path' => '/bin/echo run-shared-tests',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks job shard_alpha --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        // The success line is the canonical "name - OK. Time: X" emitted by JobExecutor.
        // Before the fix it was "/bin/echo run-shared-tests - OK. Time: X" — the executable
        // path bled into the log and shadowed the job key.
        $this->assertStringContainsString('shard_alpha - OK', $output);
        $this->assertStringNotContainsString('/bin/echo run-shared-tests - OK', $output);
    }

    /**
     * The real-world case from the bug report: two parallel script jobs with the
     * same executable. Before the fix they were indistinguishable in the output;
     * after the fix each one reports its own job key.
     *
     * @test
     */
    public function two_parallel_script_jobs_with_same_executable_are_distinguishable(): void
    {
        $this->configurationFileBuilder
            // enableV3Mode() seeds a default `pre-commit => qa` hook plus a `qa`
            // flow. setV3Flows() below replaces the flows with `shards` only, so
            // the default hook would dangle (`references 'qa' which is not a
            // defined flow`) and `flow shards` would exit 1 before running. This
            // test exercises parallel script-job display names, not hooks — drop
            // them so the redefined flow set stays self-consistent.
            ->setV3Hooks([])
            ->setV3Flows(['shards' => ['jobs' => ['shard_a', 'shard_b']]])
            ->setV3Jobs([
                'shard_a' => [
                    'type'            => 'script',
                    'executable-path' => '/bin/echo identical-runner',
                ],
                'shard_b' => [
                    'type'            => 'script',
                    'executable-path' => '/bin/echo identical-runner',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow shards --config=$this->configPath --processes=2 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('shard_a - OK', $output);
        $this->assertStringContainsString('shard_b - OK', $output);
        // The pre-fix output would have shown "/bin/echo identical-runner - OK" twice.
        $this->assertLessThanOrEqual(
            1,
            substr_count($output, '/bin/echo identical-runner - OK'),
            'Two parallel script jobs were shown with their executable instead of their job key.'
        );
    }
}
