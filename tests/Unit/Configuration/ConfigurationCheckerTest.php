<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationChecker;

/**
 * Unit tests for the pure-validation rules extracted from
 * CheckConfigurationFileCommand in Phase 3b. The 25 existing system tests
 * still cover the rendering surface; these run in milliseconds and target
 * each rule in isolation.
 *
 * `truncateCommand` lives in {@see TruncateCommandTest} (kept in its own
 * file for historical reasons — same target class).
 */
class ConfigurationCheckerTest extends UnitTestCase
{
    private ConfigurationChecker $checker;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->checker = new ConfigurationChecker();
        $this->tmpDir = sys_get_temp_dir() . '/configuration-checker-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            // Best-effort cleanup of files created by individual tests.
            foreach (glob($this->tmpDir . '/*') ?: [] as $entry) {
                if (is_dir($entry)) {
                    @rmdir($entry);
                } else {
                    @unlink($entry);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    // ───────── validateExecutable ─────────

    /** @test */
    public function validate_executable_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->checker->validateExecutable(''));
    }

    /** @test */
    public function validate_executable_returns_null_when_file_exists(): void
    {
        $path = $this->tmpDir . '/my-tool';
        file_put_contents($path, '#!/bin/sh');

        $this->assertNull($this->checker->validateExecutable($path));
    }

    /** @test */
    public function validate_executable_returns_warning_when_missing(): void
    {
        $warning = $this->checker->validateExecutable('/nonexistent/binary');

        $this->assertSame("executable '/nonexistent/binary' not found", $warning);
    }

    // ───────── validatePaths ─────────

    /** @test */
    public function validate_paths_returns_empty_for_existing_paths(): void
    {
        $dir = $this->tmpDir . '/src';
        mkdir($dir);

        $this->assertSame([], $this->checker->validatePaths([$dir]));
    }

    /** @test */
    public function validate_paths_returns_one_warning_per_missing_path(): void
    {
        $warnings = $this->checker->validatePaths(['/nope/one', '/nope/two']);

        $this->assertCount(2, $warnings);
        $this->assertSame("path '/nope/one' not found", $warnings[0]);
        $this->assertSame("path '/nope/two' not found", $warnings[1]);
    }

    /** @test */
    public function validate_paths_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->checker->validatePaths([]));
    }

    // ───────── validateConfigFiles ─────────

    /** @test */
    public function validate_config_files_checks_config_key(): void
    {
        $warnings = $this->checker->validateConfigFiles(['config' => '/nope.neon']);

        $this->assertSame(["config file '/nope.neon' not found"], $warnings);
    }

    /** @test */
    public function validate_config_files_skips_when_config_key_missing(): void
    {
        $this->assertSame([], $this->checker->validateConfigFiles([]));
    }

    /** @test */
    public function validate_config_files_treats_rules_as_path_when_containing_slash(): void
    {
        $warnings = $this->checker->validateConfigFiles(['rules' => 'qa/missing.xml']);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("rules file 'qa/missing.xml' not found", $warnings[0]);
    }

    /** @test */
    public function validate_config_files_treats_rules_as_path_when_containing_xml(): void
    {
        $warnings = $this->checker->validateConfigFiles(['rules' => 'phpmd-rules.xml']);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("rules file 'phpmd-rules.xml' not found", $warnings[0]);
    }

    /** @test */
    public function validate_config_files_ignores_symbolic_rules_csv(): void
    {
        // No slash, no .xml → treated as symbolic rule list (phpmd categories).
        $warnings = $this->checker->validateConfigFiles(['rules' => 'cleancode,codesize']);

        $this->assertSame([], $warnings);
    }

    // ───────── validateReportsPaths ─────────

    /** @test */
    public function validate_reports_paths_returns_empty_for_writable_dir(): void
    {
        $result = $this->checker->validateReportsPaths(
            ['json' => $this->tmpDir . '/qa.json'],
            'flows.options'
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    /** @test */
    public function validate_reports_paths_warns_for_missing_parent_dir(): void
    {
        $missing = $this->tmpDir . '/missing/qa.json';

        $result = $this->checker->validateReportsPaths(['json' => $missing], 'flows.options');

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('does not exist; it will be created on run', $result['warnings'][0]);
    }

    /** @test */
    public function validate_reports_paths_errors_when_existing_file_not_writable(): void
    {
        $path = $this->tmpDir . '/locked.json';
        file_put_contents($path, '{}');
        chmod($path, 0444); // read-only

        try {
            $result = $this->checker->validateReportsPaths(['json' => $path], 'flows.options');

            $this->assertCount(1, $result['errors']);
            $this->assertStringContainsString("'$path' is not writable", $result['errors'][0]);
        } finally {
            chmod($path, 0644);
        }
    }

    /** @test */
    public function validate_reports_paths_emits_context_in_messages(): void
    {
        $result = $this->checker->validateReportsPaths(
            ['sarif' => '/proc/missing/qa.sarif'],
            'flows.qa.options'
        );

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('flows.qa.options.reports.sarif', $result['warnings'][0]);
    }

    // ───────── executableExists ─────────

    /** @test */
    public function executable_exists_resolves_via_filesystem(): void
    {
        $path = $this->tmpDir . '/my-tool';
        file_put_contents($path, '#!/bin/sh');

        $this->assertTrue($this->checker->executableExists($path));
    }

    /** @test */
    public function executable_exists_resolves_via_path_lookup(): void
    {
        // `sh` exists on $PATH on every CI runner the project supports.
        $this->assertTrue($this->checker->executableExists('sh'));
    }

    /** @test */
    public function executable_exists_returns_false_for_missing(): void
    {
        $this->assertFalse($this->checker->executableExists('definitely-not-a-real-binary-' . uniqid()));
    }
}
