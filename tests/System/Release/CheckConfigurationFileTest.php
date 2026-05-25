<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CheckConfigurationFileTest extends ReleaseTestCase
{
    /** @test */
    function it_checks_the_configuration_file_and_return_exit_0()
    {
        $this->configurationFileBuilder->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_warning_and_return_exit_0()
    {
        $this->configurationFileBuilder->setOptions(['invalidOptionTest' => 1])
                                        ->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());
        $this->assertStringContainsString("The key 'invalidOptionTest' is not a valid option", $this->getActualOutput());
    }

    /** @test */
    function it_checks_the_configuration_file_and_show_error_and_return_exit_1()
    {
        $this->configurationFileBuilder->setTools([])->buildInFileSystem('./', true);

        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("The 'Tools' tag from configuration file is empty.", $this->getActualOutput());
    }

    /** @test */
    function it_checks_configuration_file_in_custom_path_with_config_flag()
    {
        $this->configurationFileBuilder->setTools(['invalid-tool'])
            ->buildInFileSystem('./', true);

        // Create valid config in custom folder
        $this->createDirStructure('custom');

        $this->configurationFileBuilder
            ->setTools(['phpunit', 'phpcs'])
                ->buildInFileSystem('custom', true);

        // Check root config with errors
        passthru("$this->githooks conf:check", $exitCode);
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString("There must be at least one tool configured.", $this->getActualOutput());

        // Check valid config in custom folder
        passthru("$this->githooks conf:check --config=custom/githooks.php", $exitCode);

        $this->assertStringContainsString('The configuration file has the correct format.', $this->getActualOutput());

        $this->deleteDirStructure('custom/');
    }

    /** @test */
    function it_shows_configuration_file_path()
    {
        $this->configurationFileBuilder->buildInFileSystem('./', true);
        passthru("$this->githooks conf:check", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Configuration file:', $this->getActualOutput());
    }

    /** @test */
    function it_checks_v3_configuration_with_correct_format()
    {
        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            self::TESTS_PATH . '/githooks.php',
            $this->configurationFileBuilder->buildV3Php()
        );

        $configPath = self::TESTS_PATH . '/githooks.php';
        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('correct format', $this->getActualOutput());
    }

    /** @test */
    function it_shows_validation_warnings_for_v3_config_with_bad_path()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => ['nonexistent_dir']],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('not found', $this->getActualOutput());
        $this->assertStringContainsString('validation warnings', $this->getActualOutput());
    }

    /** @test */
    function it_shows_conditional_execution_in_hooks_table()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Hooks([
                'pre-commit' => [
                    ['flow' => 'qa', 'only-on' => ['main', 'develop']],
                ],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('on: main', $this->getActualOutput());
    }

    // ─── 3.2 · command rendering / cores conflict warning ─────────────

    /**
     * 3.2 — `conf:check` renders generated commands with an ellipsis when
     * they exceed 80 chars (column width). The `--dry-run` path on `job`
     * still prints the full untruncated command.
     *
     * @test
     */
    function conf_check_truncates_long_commands_to_80_chars()
    {
        $this->configurationFileBuilder->enableV3Mode();
        $longScript = '/bin/echo ' . str_repeat('argument-long-enough ', 5) . 'end';
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['long_cmd_job']]])
            ->setV3Jobs([
                'long_cmd_job' => ['type' => 'custom', 'script' => $longScript],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        // conf:check renders ellipsis (ASCII `...` or Unicode `…`).
        $this->assertMatchesRegularExpression('/\.{3}|…/u', $output, 'conf:check should truncate long commands');

        passthru("$this->githooks job long_cmd_job --dry-run --config=$configPath 2>&1", $exitCode);
        $dryRunOutput = $this->getActualOutput();
        $this->assertStringContainsString('argument-long-enough argument-long-enough argument-long-enough', $dryRunOutput);
    }

    /**
     * 3.2 — when a phpcs job declares both `cores` and the tool's native
     * `parallel` flag, `conf:check` warns that `'cores' overrides 'parallel'`.
     *
     * @test
     */
    function conf_check_warns_when_cores_conflicts_with_native_flag()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_conflict']]])
            ->setV3Jobs([
                'phpcs_conflict' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => ['src'],
                    'parallel' => 8,
                    'cores'    => 2,
                ],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);
        $output = $this->getActualOutput();

        $this->assertStringContainsString("'cores' overrides 'parallel'", $output);
    }

    // ─── 3.4 · FEAT-3 DAG validation (needs cycles, missing target, dup, empty) ─

    /**
     * FEAT-3 — `conf:check` rejects a `needs` cycle and surfaces the offending
     * chain in the literal form `Flow 'qa': 'needs' has a cycle: a -> b -> a.`
     * (see FlowDependencyGraph::build()).
     *
     * @test
     */
    function conf_check_rejects_needs_cycle()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'a', 'needs' => ['b']],
                        ['job' => 'b', 'needs' => ['a']],
                    ],
                ],
            ])
            ->setV3Jobs([
                'a' => ['type' => 'custom', 'script' => 'true'],
                'b' => ['type' => 'custom', 'script' => 'true'],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertSame(1, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString("'needs' has a cycle:", $output);
        $this->assertStringContainsString('a -> b -> a', $output);
    }

    /**
     * FEAT-3 — `conf:check` rejects a `needs` target that does not exist as a
     * job declaration in the same flow.
     *
     * @test
     */
    function conf_check_rejects_needs_target_not_in_flow()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'real', 'needs' => ['ghost']],
                    ],
                ],
            ])
            ->setV3Jobs([
                'real' => ['type' => 'custom', 'script' => 'true'],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "'needs' references undefined job 'ghost'",
            $this->getActualOutput()
        );
    }

    /**
     * FEAT-3 — `conf:check` rejects the same job declared twice in the same
     * `jobs` list of a flow.
     *
     * @test
     */
    function conf_check_rejects_duplicate_job_in_flow()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Flows([
                'qa' => ['jobs' => ['dup', 'dup']],
            ])
            ->setV3Jobs([
                'dup' => ['type' => 'custom', 'script' => 'true'],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "job 'dup' is declared more than once",
            $this->getActualOutput()
        );
    }

    /**
     * FEAT-3 — `conf:check` rejects an empty `needs => []` and points the user
     * at `null` (the documented sentinel for cancelling an inherited list).
     *
     * @test
     */
    function conf_check_rejects_empty_needs_array()
    {
        $this->configurationFileBuilder->enableV3Mode()
            ->setV3Flows([
                'qa' => [
                    'jobs' => [
                        ['job' => 'a', 'needs' => []],
                    ],
                ],
            ])
            ->setV3Jobs([
                'a' => ['type' => 'custom', 'script' => 'true'],
            ]);

        $configPath = self::TESTS_PATH . '/githooks.php';
        file_put_contents($configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --config=$configPath 2>&1", $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "'needs' must not be empty. Use null to disable an inherited rule.",
            $this->getActualOutput()
        );
    }
}
