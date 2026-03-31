<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Hooks\HookInstaller;

class HookInstallerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/githooks_hooks_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        // Create a fake .git/hooks dir for legacy tests
        mkdir($this->tempDir . '/.git/hooks', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    /** @test */
    public function it_creates_hook_files_in_githooks_dir()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit', 'pre-push']);

        $this->assertCount(2, $created);
        $this->assertFileExists($this->tempDir . '/.githooks/pre-commit');
        $this->assertFileExists($this->tempDir . '/.githooks/pre-push');

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringContainsString('#!/bin/sh', $content);
        $this->assertStringContainsString('hook:run', $content);
        $this->assertStringContainsString('basename', $content);
    }

    /** @test */
    public function it_makes_hook_files_executable()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit']);

        $perms = fileperms($this->tempDir . '/.githooks/pre-commit') & 0777;
        $this->assertTrue(($perms & 0100) !== 0, 'Hook file should be executable');
    }

    /** @test */
    public function it_skips_invalid_event_names()
    {
        $installer = new HookInstaller($this->tempDir);
        $created = $installer->install(['pre-commit', 'not-a-real-hook']);

        $this->assertCount(1, $created);
        $this->assertFileDoesNotExist($this->tempDir . '/.githooks/not-a-real-hook');
    }

    /** @test */
    public function it_installs_single_event()
    {
        $installer = new HookInstaller($this->tempDir);
        $path = $installer->installSingle('pre-push');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    /** @test */
    public function it_installs_in_legacy_mode()
    {
        $installer = new HookInstaller($this->tempDir);
        $created = $installer->installLegacy(['pre-commit']);

        $this->assertCount(1, $created);
        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-commit');
    }

    /** @test */
    public function it_cleans_githooks_dir()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit', 'pre-push']);

        $this->assertDirectoryExists($this->tempDir . '/.githooks');

        $installer->clean();

        $this->assertDirectoryDoesNotExist($this->tempDir . '/.githooks');
    }

    /** @test */
    public function it_cleans_legacy_hooks()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->installLegacy(['pre-commit']);

        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-commit');

        $installer->cleanLegacy(['pre-commit']);

        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/pre-commit');
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
