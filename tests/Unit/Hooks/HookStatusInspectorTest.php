<?php

declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\HookRef;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Hooks\HookEventStatus;
use Wtyd\GitHooks\Hooks\HookStatusInspector;

class HookStatusInspectorTest extends TestCase
{
    private string $tempDir;

    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: sys_get_temp_dir();
        $this->tempDir = sys_get_temp_dir() . '/githooks_inspector_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        shell_exec('git -C ' . escapeshellarg($this->tempDir) . ' init --quiet 2>&1');
        if (!is_dir($this->tempDir . '/.git')) {
            $this->markTestSkipped('git init did not create .git — environment lacks git.');
        }
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->recursiveRemove($this->tempDir);
    }

    /** @test */
    function it_reports_hooks_path_not_configured_when_git_has_no_value()
    {
        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $this->assertFalse($report->isHooksPathConfigured());
        $this->assertSame('', $report->getHooksPathValue());
    }

    /** @test */
    function it_reports_hooks_path_configured_when_value_is_dot_githooks()
    {
        $this->gitConfig('core.hooksPath', '.githooks');

        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $this->assertTrue($report->isHooksPathConfigured());
        $this->assertSame('.githooks', $report->getHooksPathValue());
    }

    /** @test */
    function it_reports_hooks_path_not_configured_when_value_is_different_path()
    {
        $this->gitConfig('core.hooksPath', 'custom/path');

        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $this->assertFalse($report->isHooksPathConfigured());
        $this->assertSame('custom/path', $report->getHooksPathValue());
    }

    /** @test */
    function it_reports_synced_event_when_configured_and_installed_executable()
    {
        $this->installHookFile('pre-commit', 0755);

        $report = (new HookStatusInspector($this->tempDir))->inspect(
            $this->buildConfig(['pre-commit' => [new HookRef('phpcs', [], [], [])]])
        );

        $events = $report->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('pre-commit', $events[0]->getEvent());
        $this->assertSame(HookEventStatus::STATUS_SYNCED, $events[0]->getStatus());
        $this->assertTrue($events[0]->isExecutable());
        $this->assertSame(['phpcs'], $events[0]->getTargets());
    }

    /** @test */
    function it_reports_synced_but_not_executable_when_file_lacks_exec_permissions()
    {
        $this->installHookFile('pre-commit', 0644);

        $report = (new HookStatusInspector($this->tempDir))->inspect(
            $this->buildConfig(['pre-commit' => [new HookRef('phpcs', [], [], [])]])
        );

        $events = $report->getEvents();
        $this->assertSame(HookEventStatus::STATUS_SYNCED, $events[0]->getStatus());
        $this->assertFalse($events[0]->isExecutable());
    }

    /** @test */
    function it_reports_missing_event_when_configured_but_not_installed()
    {
        $report = (new HookStatusInspector($this->tempDir))->inspect(
            $this->buildConfig(['pre-commit' => [new HookRef('phpcs', [], [], [])]])
        );

        $events = $report->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame(HookEventStatus::STATUS_MISSING, $events[0]->getStatus());
        $this->assertFalse($events[0]->isExecutable());
    }

    /** @test */
    function it_reports_orphan_event_when_installed_but_not_configured()
    {
        $this->installHookFile('pre-push', 0755);

        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $events = $report->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('pre-push', $events[0]->getEvent());
        $this->assertSame(HookEventStatus::STATUS_ORPHAN, $events[0]->getStatus());
        $this->assertTrue($events[0]->isExecutable());
    }

    /** @test */
    function it_reports_orphan_non_executable_when_installed_file_lacks_exec()
    {
        $this->installHookFile('pre-push', 0644);

        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $events = $report->getEvents();
        $this->assertSame(HookEventStatus::STATUS_ORPHAN, $events[0]->getStatus());
        $this->assertFalse($events[0]->isExecutable());
    }

    /** @test */
    function it_returns_empty_event_list_when_githooks_directory_missing()
    {
        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $this->assertSame([], $report->getEvents());
    }

    /** @test */
    function it_filters_out_dot_and_dotdot_from_installed_events()
    {
        mkdir($this->tempDir . '/.githooks', 0755, true);

        $report = (new HookStatusInspector($this->tempDir))->inspect($this->buildConfig([]));

        $this->assertSame([], $report->getEvents());
    }

    /** @test */
    function it_reports_both_synced_and_orphan_events_together()
    {
        $this->installHookFile('pre-commit', 0755);
        $this->installHookFile('pre-push', 0755);

        $report = (new HookStatusInspector($this->tempDir))->inspect(
            $this->buildConfig(['pre-commit' => [new HookRef('phpcs', [], [], [])]])
        );

        $events = $report->getEvents();
        $this->assertCount(2, $events);
        $this->assertSame('pre-commit', $events[0]->getEvent());
        $this->assertSame(HookEventStatus::STATUS_SYNCED, $events[0]->getStatus());
        $this->assertSame('pre-push', $events[1]->getEvent());
        $this->assertSame(HookEventStatus::STATUS_ORPHAN, $events[1]->getStatus());
    }

    /** @test */
    function it_handles_null_hook_configuration()
    {
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [],
            null,
            new ValidationResult()
        );

        $report = (new HookStatusInspector($this->tempDir))->inspect($config);

        $this->assertSame([], $report->getEvents());
    }

    /** @test */
    function it_exposes_multiple_targets_for_configured_event()
    {
        $refs = [
            new HookRef('phpcs', [], [], []),
            new HookRef('phpstan', [], [], []),
        ];

        $report = (new HookStatusInspector($this->tempDir))->inspect(
            $this->buildConfig(['pre-commit' => $refs])
        );

        $events = $report->getEvents();
        $this->assertSame(['phpcs', 'phpstan'], $events[0]->getTargets());
    }

    /**
     * @param array<string, HookRef[]> $hookRefsByEvent
     */
    private function buildConfig(array $hookRefsByEvent): ConfigurationResult
    {
        $hooks = empty($hookRefsByEvent) ? new HookConfiguration([]) : new HookConfiguration($hookRefsByEvent);

        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            ['phpcs' => new JobConfiguration('phpcs', 'phpcs', []), 'phpstan' => new JobConfiguration('phpstan', 'phpstan', [])],
            [],
            $hooks,
            new ValidationResult()
        );
    }

    private function installHookFile(string $event, int $mode): void
    {
        $dir = $this->tempDir . '/.githooks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $event;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, $mode);
    }

    private function gitConfig(string $key, string $value): void
    {
        shell_exec(sprintf(
            'git -C %s config %s %s 2>&1',
            escapeshellarg($this->tempDir),
            escapeshellarg($key),
            escapeshellarg($value)
        ));
    }

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
