<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Hooks\HookInstaller;

/**
 * Tests targeting escaped mutants in HookInstaller:
 * install, installLegacy, clean, cleanLegacy, buildScript, chmod.
 */
class HookInstallerMutationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/githooks_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        shell_exec('git -C ' . escapeshellarg($this->tempDir) . ' init --quiet 2>&1');
        if (!is_dir($this->tempDir . '/.git/hooks')) {
            $this->markTestSkipped('git init did not create .git/hooks — environment lacks git.');
        }
    }

    private function readCoreHooksPath(): ?string
    {
        $cmd = 'git -C ' . escapeshellarg($this->tempDir) . ' config --get core.hooksPath 2>/dev/null';
        $out = shell_exec($cmd);
        if ($out === null) {
            return null;
        }
        $trimmed = trim($out);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->tempDir);
    }

    // ========================================================================
    // install()
    // ========================================================================

    /** @test */
    public function install_creates_githooks_directory_if_not_exists()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit']);

        $this->assertDirectoryExists($this->tempDir . '/.githooks');
    }

    /** @test */
    public function install_creates_hook_file_for_valid_event()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit']);

        $this->assertCount(1, $created);
        $this->assertFileExists($created[0]);
    }

    /** @test */
    public function install_skips_invalid_events()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit', 'not-a-valid-hook', 'pre-push']);

        $this->assertCount(2, $created);
        $hookNames = array_map('basename', $created);
        $this->assertContains('pre-commit', $hookNames);
        $this->assertContains('pre-push', $hookNames);
        $this->assertNotContains('not-a-valid-hook', $hookNames);
    }

    /** @test */
    public function install_sets_file_permissions_to_0755()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit']);

        $this->assertSame(0755, fileperms($created[0]) & 0777);
    }

    /** @test */
    public function install_sets_githooks_directory_permissions_to_0755()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit']);

        $this->assertSame(0755, fileperms($this->tempDir . '/.githooks') & 0777);
    }

    /** @test */
    public function install_configures_core_hooks_path_to_githooks()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit']);

        $this->assertSame('.githooks', $this->readCoreHooksPath());
    }

    /** @test */
    public function install_preserves_current_working_directory()
    {
        $installer = new HookInstaller($this->tempDir);
        $cwdBefore = getcwd();

        $installer->install(['pre-commit']);

        $this->assertSame($cwdBefore, getcwd());
    }

    /** @test */
    public function install_returns_correct_file_paths()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit', 'pre-push']);

        foreach ($created as $path) {
            $this->assertStringStartsWith($this->tempDir . '/.githooks/', $path);
        }
    }

    // ========================================================================
    // buildScript
    // ========================================================================

    /** @test */
    public function install_with_custom_command_uses_it_in_script()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit'], 'php7.4 vendor/bin/githooks');

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringContainsString('php7.4 vendor/bin/githooks', $content);
    }

    /** @test */
    public function install_with_empty_command_uses_default()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit'], '');

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringContainsString('php vendor/bin/githooks', $content);
    }

    /** @test */
    public function script_contains_shebang_and_hook_run()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit']);

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringStartsWith('#!/bin/sh', $content);
        $this->assertStringContainsString('hook:run', $content);
        $this->assertStringContainsString('basename', $content);
    }

    // ========================================================================
    // installSingle
    // ========================================================================

    /** @test */
    public function installSingle_returns_path_for_valid_event()
    {
        $installer = new HookInstaller($this->tempDir);

        $path = $installer->installSingle('pre-commit');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    /** @test */
    public function installSingle_returns_null_for_invalid_event()
    {
        $installer = new HookInstaller($this->tempDir);

        $path = $installer->installSingle('not-a-hook');

        $this->assertNull($path);
    }

    // ========================================================================
    // installLegacy
    // ========================================================================

    /** @test */
    public function installLegacy_creates_hooks_in_git_hooks_dir()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->installLegacy(['pre-commit']);

        $this->assertCount(1, $created);
        $this->assertStringContainsString('.git/hooks/pre-commit', str_replace(DIRECTORY_SEPARATOR, '/', $created[0]));
        $this->assertFileExists($created[0]);
    }

    /** @test */
    public function installLegacy_returns_empty_when_git_hooks_dir_missing()
    {
        // Create a temp dir without .git/hooks
        $noGitDir = sys_get_temp_dir() . '/githooks_nogit_' . uniqid();
        mkdir($noGitDir, 0755, true);

        $installer = new HookInstaller($noGitDir);
        $created = $installer->installLegacy(['pre-commit']);

        $this->assertEmpty($created);

        $this->recursiveRemove($noGitDir);
    }

    /** @test */
    public function installLegacy_skips_invalid_events()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->installLegacy(['pre-commit', 'invalid-hook']);

        $this->assertCount(1, $created);
    }

    /** @test */
    public function installLegacy_sets_file_permissions_to_0755()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->installLegacy(['pre-commit']);

        $this->assertSame(0755, fileperms($created[0]) & 0777);
    }

    // ========================================================================
    // clean
    // ========================================================================

    /** @test */
    public function clean_removes_githooks_directory_and_files()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit', 'pre-push']);

        $this->assertDirectoryExists($this->tempDir . '/.githooks');

        $installer->clean();

        $this->assertDirectoryDoesNotExist($this->tempDir . '/.githooks');
    }

    /** @test */
    public function clean_does_not_crash_when_githooks_dir_missing()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->clean();

        $this->assertDirectoryDoesNotExist($this->tempDir . '/.githooks');
    }

    /** @test */
    public function clean_unsets_core_hooks_path()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit']);
        $this->assertSame('.githooks', $this->readCoreHooksPath());

        $installer->clean();

        $this->assertNull($this->readCoreHooksPath());
    }

    /** @test */
    public function clean_preserves_current_working_directory()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit']);
        $cwdBefore = getcwd();

        $installer->clean();

        $this->assertSame($cwdBefore, getcwd());
    }

    // ========================================================================
    // cleanLegacy
    // ========================================================================

    /** @test */
    public function cleanLegacy_removes_specified_events()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->installLegacy(['pre-commit', 'pre-push']);

        $installer->cleanLegacy(['pre-commit']);

        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/pre-commit');
        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-push');
    }

    /** @test */
    public function cleanLegacy_with_empty_events_removes_all_known_hooks()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->installLegacy(['pre-commit', 'pre-push']);

        $installer->cleanLegacy([]);

        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/pre-commit');
        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/pre-push');
    }

    /** @test */
    public function cleanLegacy_does_not_crash_on_nonexistent_files()
    {
        $installer = new HookInstaller($this->tempDir);

        // Should not throw even if files don't exist
        $installer->cleanLegacy(['pre-commit', 'pre-push']);

        $this->assertTrue(true); // No exception thrown
    }

    // ========================================================================
    // Helper
    // ========================================================================

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
