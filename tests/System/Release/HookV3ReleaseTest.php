<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class HookV3ReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );
    }

    protected function tearDown(): void
    {
        shell_exec('git config --unset core.hooksPath 2>/dev/null');
        if (is_dir('.githooks')) {
            array_map('unlink', glob('.githooks/*') ?: []);
            @rmdir('.githooks');
        }

        parent::tearDown();
    }

    /** @test */
    public function it_installs_hooks_via_core_hooks_path()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('installed', $this->getActualOutput());
        $this->assertDirectoryExists('.githooks');
        $this->assertFileExists('.githooks/pre-commit');
    }

    /** @test */
    public function it_cleans_hooks()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);
        $this->assertEquals(0, $exitCode);

        passthru("$this->githooks hook:clean 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist('.githooks');
    }

    /** @test */
    public function it_shows_synced_status_after_install()
    {
        passthru("$this->githooks hook --config=$this->configPath 2>&1", $exitCode);
        $this->assertEquals(0, $exitCode);

        passthru("$this->githooks status --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('synced', $this->getActualOutput());
    }

    /** @test */
    public function it_runs_hook_event()
    {
        passthru("$this->githooks hook:run pre-commit --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('OK', $this->getActualOutput());
    }
}
