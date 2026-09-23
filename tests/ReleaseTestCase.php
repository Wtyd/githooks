<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Tests\Utils\Traits\FileSystemTrait;
use Wtyd\GitHooks\Build\Build;
use Wtyd\GitHooks\Exception\ExitException;

class ReleaseTestCase extends TestCase
{
    use FileSystemTrait;

    public const TESTS_PATH = SystemTestCase::TESTS_PATH;
    /**
     * Executable binary
     *
     * @var string
     */
    protected $githooks = SystemTestCase::TESTS_PATH . DIRECTORY_SEPARATOR . 'githooks';

    /**
     * @var ConfigurationFileBuilder
     */
    protected $configurationFileBuilder;

    /**
     * @var PhpFileBuilder
     */
    protected $phpFileBuilder;

    /**
     * The project's own hook wiring, captured once so any test that installs
     * or cleans hooks can be put back the way it found things.
     *
     * @var array{hooksPath: string, scripts: array<string, string>, gitignore: ?string}|null
     */
    private static $projectHooks = null;

    protected function setUp(): void
    {
        self::rememberProjectHooks();

        $this->deleteDirStructure();

        $this->hiddenConsoleOutput();

        $this->createDirStructure();

        self::copyReleaseBinary();

        $this->configurationFileBuilder = new ConfigurationFileBuilder($this->path);

        $this->phpFileBuilder = new PhpFileBuilder('File');
    }


    protected function tearDown(): void
    {
        $this->deleteDirStructure();
        @unlink('githooks.php');
        @unlink('githooks.yml');
        shell_exec('git restore -- ' . self::TESTS_PATH . "/.gitignore");
        self::restoreProjectHooks();
    }

    /**
     * These tests drive the real binary from the repository root, so the hook
     * commands operate on the repository's own wiring: `hook` writes into
     * `.githooks/` and sets `core.hooksPath`, `hook:clean` deletes and unsets
     * both. Left alone, a release run strips the project of the pre-commit hook
     * that gates every commit with the QA flow — silently, since git simply
     * stops having a hook to call.
     *
     * Captured once per process: the project's wiring does not change between
     * tests, only what the tests do to it.
     */
    private static function rememberProjectHooks(): void
    {
        if (self::$projectHooks !== null) {
            return;
        }

        $scripts = [];
        foreach (glob('.githooks/*') ?: [] as $file) {
            if (is_file($file)) {
                $scripts[$file] = (string) file_get_contents($file);
            }
        }

        self::$projectHooks = [
            'hooksPath' => trim((string) shell_exec('git config core.hooksPath 2>/dev/null')),
            'scripts'   => $scripts,
            // `conf:init` appends `.githooks/history/` to the project's
            // .gitignore — correct behaviour, aimed at the user's repository,
            // but these tests run it against ours.
            'gitignore' => is_file('.gitignore') ? (string) file_get_contents('.gitignore') : null,
        ];
    }

    private static function restoreProjectHooks(): void
    {
        if (self::$projectHooks === null) {
            return;
        }

        $expected = self::$projectHooks['hooksPath'];
        if (trim((string) shell_exec('git config core.hooksPath 2>/dev/null')) !== $expected) {
            shell_exec($expected === ''
                ? 'git config --unset core.hooksPath 2>/dev/null'
                : 'git config core.hooksPath ' . escapeshellarg($expected));
        }

        foreach (self::$projectHooks['scripts'] as $file => $contents) {
            if (is_file($file) && (string) file_get_contents($file) === $contents) {
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            file_put_contents($file, $contents);
            chmod($file, 0755);
        }

        $gitignore = self::$projectHooks['gitignore'];
        if ($gitignore === null) {
            @unlink('.gitignore');
        } elseif ((string) @file_get_contents('.gitignore') !== $gitignore) {
            file_put_contents('.gitignore', $gitignore);
        }
    }

    protected function hiddenConsoleOutput(): void
    {
        $this->setOutputCallback(function () {
        });
    }


    /**
     * Copies de releases candidate to the tests directory. Only copies the version that works in the current php version.
     * Permissions must be setted. Otherwise, the Github Action flow will fail.
     */
    protected static function copyReleaseBinary(): bool
    {
        $build = new Build();
        $origin = $build->getBuildPath() . 'githooks';
        $destiny = SystemTestCase::TESTS_PATH . DIRECTORY_SEPARATOR . 'githooks';
        copy($origin, $destiny);
        return chmod($destiny, 0777);
    }

    /**
     * Checks if the $tool has been executed Successfully by regular expression assert. This assert was renamed and is deprecated
     * sinse phpunit 9.
     *
     * @param string $tool
     * @return void
     */
    protected function assertToolHasBeenExecutedSuccessfully(string $tool): void
    {
        //phpcbf - OK. Time: 60ms | 0.18s | 2m 5s
        $this->assertMatchesRegularExpression("%$tool - OK\. Time: (\d+ms|\d+\.\d{2}s|\d+m \d+s)%", $this->getActualOutput(), "The tool $tool has not been executed successfully");
    }

    protected function assertToolHasFailed(string $tool): void
    {
        //phpcbf - KO. Time: 60ms | 0.18s | 2m 5s
        $this->assertMatchesRegularExpression("%$tool - KO\. Time: (\d+ms|\d+\.\d{2}s|\d+m \d+s)%", $this->getActualOutput(), "The tool $tool has not failed");
    }

    protected function assertToolDidNotRun(string $tool): void
    {
        $this->assertStringNotContainsString($tool, $this->getActualOutput(), "The tool $tool has been run");
    }

    /**
     * Verifies that some tool of all launched has failed.
     * 1. ExitException must be throwed.
     * 2. The summation of run time of all tools.
     * 3. Fail message.
     *
     * @param \Throwable $exception All errors and exceptions are cached for no break phpunit's flow.
     * @param string $failMessage Message printed when GitHooks finds an error.
     * @return void
     */
    protected function assertSomeToolHasFailed(\Throwable $exception, string $failMessage): void
    {
        $this->assertInstanceOf(ExitException::class, $exception);
        $this->assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString($failMessage, $this->getActualOutput());
    }
}
