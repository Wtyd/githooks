<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;
use Tests\Utils\Traits\ProcessTreeFixtureTrait;

/**
 * Release guard for the process-tree kill (BUG-35, 3.8): the compiled .phar
 * must terminate the whole tree of a job when `memory-budget.fail-above`
 * fires. Same 3-level fixture as the system test (wrapper → inner shell →
 * sleeper + memory burner), asserted by PID against the OS, so a binary
 * shipped without the fix fails here with "survived the kill".
 *
 * @group release
 */
class ProcessTreeKillReleaseTest extends ReleaseTestCase
{
    use ProcessTreeFixtureTrait;

    private string $base;

    private string $shellPidFile;

    private string $sleeperPidFile;

    private string $burnerPidFile;

    protected function setUp(): void
    {
        $this->skipUnlessTreeKillSupported();
        parent::setUp();

        $this->base = getcwd() . '/' . self::TESTS_PATH;
        $this->shellPidFile = $this->base . '/inner-shell.pid';
        $this->sleeperPidFile = $this->base . '/sleeper.pid';
        $this->burnerPidFile = $this->base . '/burner.pid';
    }

    protected function tearDown(): void
    {
        foreach ([$this->burnerPidFile, $this->sleeperPidFile, $this->shellPidFile] as $pidFile) {
            if (is_file($pidFile)) {
                $this->killIfAlive((int) trim((string) file_get_contents($pidFile)));
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function phar_kills_the_whole_process_tree_when_memory_budget_fail_above_fires(): void
    {
        $burner = getcwd() . '/tests/Fixtures/scripts/memory-burner.php';
        $config = [
            'flows' => [
                'options' => ['memory-budget' => ['warn-above' => 16, 'fail-above' => 32]],
                'qa' => ['jobs' => ['tree']],
            ],
            'jobs' => [
                'tree' => [
                    'type'   => 'custom',
                    'script' => $this->sleeperTreeScript($this->shellPidFile, $this->sleeperPidFile, $this->burnerPidFile, $burner, 64),
                ],
            ],
        ];
        file_put_contents($this->base . '/githooks.php', "<?php\nreturn " . var_export($config, true) . ";\n");

        $output = [];
        exec(
            sprintf('cd %s && ./githooks flow qa --format=json --config=githooks.php 2>/dev/null', self::TESTS_PATH),
            $output,
            $exitCode
        );
        $decoded = json_decode(implode("\n", $output), true);

        $this->assertSame(1, $exitCode, 'the flow must fail once the budget is crossed');
        $this->assertIsArray($decoded, 'stdout is not JSON: ' . implode("\n", $output));
        $this->assertSame('flow memory-budget exceeded', $decoded['jobs'][0]['killedReason'] ?? null);
        $this->assertProcessGone($this->readPidFile($this->shellPidFile), 'inner shell');
        $this->assertProcessGone($this->readPidFile($this->sleeperPidFile), 'sleeper (grandchild of the wrapper)');
        $this->assertProcessGone($this->readPidFile($this->burnerPidFile), 'memory burner (the process holding the RAM)');
    }
}
