<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the `executable-prefix` option introduced in 3.1 and
 * extended by BUG-20 (per-key cascade from `flows.options` when a flow
 * declares its own `options:` block).
 *
 * @group release
 */
class ExecutablePrefixReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function executable_prefix_is_prepended_to_command(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo PREFIX'])
            ->setV3Flows(['qa' => ['jobs' => ['my_job']]])
            ->setV3Jobs([
                'my_job' => [
                    'type' => 'custom',
                    'executablePath' => 'original-command',
                    'paths' => ['src'],
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('PREFIX original-command', $this->getActualOutput());
    }

    /** @test */
    public function per_job_executable_prefix_overrides_global(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => 'tool-a',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => 'tool-b',
                    'paths' => ['src'],
                    'executable-prefix' => '/bin/echo LOCAL',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('GLOBAL tool-a', $output);
        $this->assertStringContainsString('LOCAL tool-b', $output);
    }

    /** @test */
    public function per_job_null_prefix_opts_out_of_global(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'executable-prefix' => null,
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('GLOBAL /bin/echo', $output);
        $this->assertMatchesRegularExpression('/job_b.*\n\s+\/bin\/echo src/', $output);
    }

    /** @test */
    public function per_job_empty_prefix_opts_out_of_global(): void
    {
        $this->configurationFileBuilder
            ->setV3GlobalOptions(['executable-prefix' => '/bin/echo GLOBAL'])
            ->setV3Flows(['qa' => ['jobs' => ['job_a', 'job_b']]])
            ->setV3Jobs([
                'job_a' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                ],
                'job_b' => [
                    'type' => 'custom',
                    'executablePath' => '/bin/echo',
                    'paths' => ['src'],
                    'executable-prefix' => '',
                ],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --dry-run --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('GLOBAL /bin/echo', $output);
        $this->assertMatchesRegularExpression('/job_b.*\n\s+\/bin\/echo src/', $output);
    }

    /**
     * BUG-20 (3.4) — `executable-prefix` declared at `flows.options` must
     * cascade per-key when a flow declares its own `options:` block to
     * override an unrelated key. Prior to the fix, declaring any per-flow
     * `options:` silently dropped the global value.
     *
     * @test
     */
    public function phar_cascades_executable_prefix_per_key_when_flow_declares_options(): void
    {
        $config = [
            'flows' => [
                'options' => ['executable-prefix' => 'echo PREFIX_HIT'],
                'qa' => [
                    'options' => ['fail-fast' => true],
                    'jobs' => ['noop_job'],
                ],
            ],
            'jobs' => [
                'noop_job' => [
                    'type' => 'custom',
                    'executable-path' => 'true',
                    'paths' => ['.'],
                ],
            ],
        ];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        passthru(
            sprintf(
                '%s flow qa --dry-run --format=json --config=%s 2>/dev/null',
                $this->githooks,
                $this->configPath
            ),
            $exitCode
        );

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $command = (string) ($decoded['jobs'][0]['command'] ?? '');
        $this->assertStringStartsWith(
            'echo PREFIX_HIT ',
            $command,
            'Global executable-prefix must cascade per-key when flow declares an options block'
        );
    }
}
