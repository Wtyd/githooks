<?php

declare(strict_types=1);

namespace Tests\System\Commands;

use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\ProcessTreeFixtureTrait;

/**
 * BUG-35 end-to-end through `flow`: when `memory-budget.fail-above` fires,
 * the whole process tree of the job in flight must die — not just the
 * `sh -c` wrapper Symfony Process spawned. The job is a 3-level tree
 * (wrapper → inner shell → sleeper + memory burner); the inner shell writes
 * its PID and the sleeper's to files so liveness can be asserted by PID,
 * independently of the SUT, once the flow has exited 1.
 */
class MemoryBudgetKillTest extends SystemTestCase
{
    use ProcessTreeFixtureTrait;

    private string $configPath;

    private string $shellPidFile;

    private string $sleeperPidFile;

    private string $burnerPidFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessTreeKillSupported();

        $base = getcwd() . '/' . self::TESTS_PATH;
        $this->configPath = $base . '/githooks.php';
        $this->shellPidFile = $base . '/inner-shell.pid';
        $this->sleeperPidFile = $base . '/sleeper.pid';
        $this->burnerPidFile = $base . '/burner.pid';
    }

    protected function tearDown(): void
    {
        // A red run must not leak the 30 s fixture processes.
        foreach ([$this->burnerPidFile, $this->sleeperPidFile, $this->shellPidFile] as $pidFile) {
            if (is_file($pidFile)) {
                $this->killIfAlive((int) trim((string) file_get_contents($pidFile)));
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function fail_above_kills_the_whole_process_tree_of_the_job_in_flight(): void
    {
        $burner = getcwd() . '/tests/Fixtures/scripts/memory-burner.php';
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions(['memory-budget' => ['warn-above' => 16, 'fail-above' => 32]])
            ->setV3Flows(['qa' => ['jobs' => ['tree']]])
            ->setV3Jobs([
                'tree' => [
                    'type'   => 'custom',
                    // 64 MB in the burner: the budget is only crossed once the whole tree exists.
                    'script' => $this->sleeperTreeScript($this->shellPidFile, $this->sleeperPidFile, $this->burnerPidFile, $burner, 64),
                ],
            ])
            ->buildInFileSystem();

        $this->artisan("flow qa --config=$this->configPath")->assertExitCode(1);

        $this->assertProcessGone($this->readPidFile($this->shellPidFile), 'inner shell');
        $this->assertProcessGone($this->readPidFile($this->sleeperPidFile), 'sleeper (grandchild of the wrapper)');
        $this->assertProcessGone($this->readPidFile($this->burnerPidFile), 'memory burner (the process holding the RAM)');
    }

    /**
     * Killing what is in flight is half the contract (REQ-013): the jobs still
     * queued behind it must be reported as skipped, with the budget as the
     * reason, so the operator can tell "did not run" from "ran and passed".
     * The test above declares a single job, so the queue is empty and the loop
     * that emits those results never runs.
     *
     * @test
     */
    public function fail_above_reports_the_jobs_left_in_the_queue_as_skipped(): void
    {
        // Deliberately NOT the process-tree fixture: this case is about the
        // report, and sharing the pid files with the test above makes the two
        // race each other over the same paths. A lone burner is enough to
        // cross the budget.
        $burner = getcwd() . '/tests/Fixtures/scripts/memory-burner.php';
        $report = getcwd() . '/' . self::TESTS_PATH . '/queued-report.json';
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'memory-budget' => ['warn-above' => 16, 'fail-above' => 32],
                'processes'     => 1,
            ])
            ->setV3Flows(['qa' => ['jobs' => ['hog', 'queued']]])
            ->setV3Jobs([
                'hog' => [
                    'type'   => 'custom',
                    'script' => sprintf('%s %s 64 5', PHP_BINARY, $burner),
                ],
                'queued' => [
                    'type'   => 'custom',
                    'script' => 'echo queued-should-never-run',
                ],
            ])
            ->buildInFileSystem();

        try {
            $this->artisan("flow qa --config=$this->configPath --report-json=$report")->assertExitCode(1);

            $decoded = json_decode((string) file_get_contents($report), true);
            $queued = null;
            foreach ($decoded['jobs'] as $job) {
                if ($job['name'] === 'queued') {
                    $queued = $job;
                }
            }

            $this->assertNotNull($queued, 'the queued job must appear in the report');
            $this->assertTrue($queued['skipped'], 'it never ran, so it cannot be reported as executed');
            $this->assertSame('flow memory-budget exceeded', $queued['skipReason']);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }
}
