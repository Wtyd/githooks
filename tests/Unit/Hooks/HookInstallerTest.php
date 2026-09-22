<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Hooks\HookInstaller;

class HookInstallerTest extends UnitTestCase
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

    /** @test FEAT-16: script forwards Git's hook arguments ("$@") to the engine. */
    public function it_propagates_git_hook_arguments()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['commit-msg']);

        $content = file_get_contents($this->tempDir . '/.githooks/commit-msg');
        $this->assertStringContainsString('hook:run "$(basename "$0")" "$@"', $content);
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

    /**
     * Two-elements rule on the install loop: with the invalid event in the
     * MIDDLE, `continue` and `break` diverge — the events after the skip must
     * still be installed and the returned list must be complete. Kills the
     * escaped Continue_→break (HookInstaller:82) and ArrayOneItem (:90)
     * mutants, which `it_skips_invalid_event_names` (invalid event LAST)
     * cannot distinguish.
     *
     * @test
     */
    public function it_keeps_installing_after_skipping_an_invalid_event_in_the_middle()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->install(['pre-commit', 'not-a-real-hook', 'pre-push']);

        $this->assertSame(
            [
                $this->tempDir . '/.githooks/pre-commit',
                $this->tempDir . '/.githooks/pre-push',
            ],
            $created,
            'both valid events must be installed and reported, in order'
        );
        $this->assertFileExists($this->tempDir . '/.githooks/pre-push');
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

    /**
     * Same contract as `install()`, on the legacy `.git/hooks` path: an
     * invalid event in the middle is skipped, not a stop sign, and every
     * valid one is reported. A single-event fixture cannot tell `continue`
     * from `break`, nor a full list from a truncated one.
     *
     * @test
     */
    public function legacy_install_keeps_going_after_an_invalid_event_and_reports_every_hook()
    {
        $installer = new HookInstaller($this->tempDir);

        $created = $installer->installLegacy(['pre-commit', 'not-a-real-hook', 'pre-push']);

        $this->assertSame(
            [
                $this->tempDir . '/.git/hooks/pre-commit',
                $this->tempDir . '/.git/hooks/pre-push',
            ],
            $created,
            'both valid events must be installed and reported, in order'
        );
        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-push');
        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/not-a-real-hook');
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

    /** @test */
    public function it_uses_custom_command_in_hook_script()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit'], 'php7.4 vendor/bin/githooks');

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringContainsString('php7.4 vendor/bin/githooks hook:run', $content);
        $this->assertStringNotContainsString('php vendor/bin/githooks', $content);
    }

    /** @test */
    public function it_uses_default_command_when_empty()
    {
        $installer = new HookInstaller($this->tempDir);
        $installer->install(['pre-commit'], '');

        $content = file_get_contents($this->tempDir . '/.githooks/pre-commit');
        $this->assertStringContainsString('php vendor/bin/githooks hook:run', $content);
    }

    /** @test BUG-31: the install config is baked into the script, single-quoted, before the event. */
    public function it_bakes_the_config_path_into_the_script_when_given()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit'], '', 'qa/githooks.php');

        $this->assertSame(
            "#!/bin/sh\n# Generated by GitHooks — do not edit manually\n"
            . "php vendor/bin/githooks hook:run --config='qa/githooks.php' \"$(basename \"$0\")\" \"$@\"\n",
            file_get_contents($this->tempDir . '/.githooks/pre-commit')
        );
    }

    /** @test BUG-31: no --config at install time → script identical to before (default lookup at run time). */
    public function it_leaves_the_script_unchanged_without_a_config_path()
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit'], 'php7.4 vendor/bin/githooks');

        $this->assertSame(
            "#!/bin/sh\n# Generated by GitHooks — do not edit manually\n"
            . "php7.4 vendor/bin/githooks hook:run \"$(basename \"$0\")\" \"$@\"\n",
            file_get_contents($this->tempDir . '/.githooks/pre-commit')
        );
    }

    /**
     * @test
     * @dataProvider quotedConfigPaths
     * The path is a shell literal: spaces survive and a single quote is escaped the sh way.
     */
    public function it_quotes_the_config_path_for_the_shell(string $configPath, string $expectedFlag)
    {
        $installer = new HookInstaller($this->tempDir);

        $installer->install(['pre-commit'], '', $configPath);

        $this->assertStringContainsString(" hook:run $expectedFlag \"$(basename", file_get_contents($this->tempDir . '/.githooks/pre-commit'));
    }

    /** @return array<string, array{string, string}> */
    public function quotedConfigPaths(): array
    {
        return [
            'space in the path'        => ['my conf/githooks.php', "--config='my conf/githooks.php'"],
            'single quote in the path' => ["it's/githooks.php", "--config='it'\\''s/githooks.php'"],
        ];
    }

    /**
     * @test
     * @dataProvider scriptConfigPaths
     * BUG-31 decision table for scriptConfigPath(): inside the root → relative to
     * it (portable across clones); outside → absolute. `{root}` / `{outside}` are
     * replaced with real, realpath-normalised temp dirs (providers run before setUp).
     */
    public function script_config_path_is_relative_inside_the_root_and_absolute_outside(string $given, string $expected)
    {
        $root = (string) realpath($this->tempDir);
        $outside = sys_get_temp_dir() . '/githooks_outside_' . uniqid();
        mkdir($outside, 0755, true);
        $outside = (string) realpath($outside);
        mkdir("$root/qa", 0755, true);
        mkdir("$root/my conf", 0755, true);
        foreach (["$root/githooks.php", "$root/qa/githooks.php", "$root/my conf/githooks.php", "$outside/githooks.php"] as $file) {
            file_put_contents($file, '<?php return [];');
        }
        $replace = ['{root}' => $root, '{outside}' => $outside, '{outsideName}' => basename($outside)];

        try {
            $installer = new HookInstaller($root);

            $this->assertSame(strtr($expected, $replace), $installer->scriptConfigPath(strtr($given, $replace)));
        } finally {
            $this->recursiveDelete($outside);
        }
    }

    /** @return array<string, array{string, string}> */
    public function scriptConfigPaths(): array
    {
        return [
            'relative, inside the root'                  => ['qa/githooks.php', 'qa/githooks.php'],
            'absolute, inside the root'                  => ['{root}/qa/githooks.php', 'qa/githooks.php'],
            'relative with .. that stays inside'         => ['qa/../githooks.php', 'githooks.php'],
            'relative with spaces, inside the root'      => ['my conf/githooks.php', 'my conf/githooks.php'],
            'relative to a file that does not exist yet' => ['missing/githooks.php', 'missing/githooks.php'],
            'absolute, outside the root'                 => ['{outside}/githooks.php', '{outside}/githooks.php'],
            'relative with .. escaping the root'         => ['../{outsideName}/githooks.php', '{outside}/githooks.php'],
        ];
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
