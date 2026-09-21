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
}
