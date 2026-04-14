<?php

declare(strict_types=1);

namespace Tests\Unit\Windows;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpCsFixerJob;
use Wtyd\GitHooks\Jobs\RectorJob;

/**
 * Cross-platform path handling tests.
 * These run on all platforms — they verify that jobs handle various path
 * formats correctly in buildCommand().
 */
class PathHandlingTest extends TestCase
{
    /** @test */
    public function phpstan_handles_backslash_paths_in_config()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'config'         => 'qa\\phpstan.neon',
            'paths'          => ['src\\Models'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('qa\\phpstan.neon', $command);
        $this->assertStringContainsString('src\\Models', $command);
    }

    /** @test */
    public function phpcs_handles_backslash_in_paths()
    {
        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', [
            'executablePath' => 'vendor/bin/phpcs',
            'paths'          => ['src\\Controllers', 'app\\Http'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringEndsWith('src\\Controllers app\\Http', $command);
    }

    /** @test */
    public function php_cs_fixer_handles_windows_config_path()
    {
        $job = new PhpCsFixerJob(new JobConfiguration('fixer', 'php-cs-fixer', [
            'executablePath' => 'vendor\\bin\\php-cs-fixer',
            'config'         => 'C:\\project\\.php-cs-fixer.dist.php',
            'paths'          => ['src'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor\\bin\\php-cs-fixer fix', $command);
        $this->assertStringContainsString('--config=C:\\project\\.php-cs-fixer.dist.php', $command);
    }

    /** @test */
    public function rector_handles_windows_config_path()
    {
        $job = new RectorJob(new JobConfiguration('rector', 'rector', [
            'executablePath' => 'vendor\\bin\\rector',
            'config'         => 'C:\\project\\rector.php',
            'paths'          => ['src\\Domain'],
        ]));

        $command = $job->buildCommand();

        $this->assertStringContainsString('vendor\\bin\\rector process', $command);
        $this->assertStringContainsString('--config=C:\\project\\rector.php', $command);
        $this->assertStringEndsWith('src\\Domain', $command);
    }

    /** @test */
    public function job_configuration_resolves_windows_absolute_paths()
    {
        $config = new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['C:\\Users\\dev\\project\\src'],
        ]);

        $this->assertSame(['C:\\Users\\dev\\project\\src'], $config->getPaths());
    }

    /** @test */
    public function with_paths_preserves_backslash_paths()
    {
        $config = new JobConfiguration('phpstan_src', 'phpstan', [
            'paths' => ['src'],
        ]);

        $modified = $config->withPaths(['src\\Foo.php', 'src\\Bar.php']);

        $this->assertSame(['src\\Foo.php', 'src\\Bar.php'], $modified->getPaths());
    }

    /** @test */
    public function file_reader_pattern_matches_windows_drive_letters()
    {
        // The pattern used by FileReader to detect absolute paths
        $pattern = '/^[a-zA-Z]:[\\\\\/]/';

        $this->assertMatchesRegularExpression($pattern, 'C:\\project\\githooks.php');
        $this->assertMatchesRegularExpression($pattern, 'D:/project/githooks.php');
        $this->assertMatchesRegularExpression($pattern, 'c:\\Users\\dev\\config.php');
        $this->assertDoesNotMatchRegularExpression($pattern, 'relative/path.php');
        $this->assertDoesNotMatchRegularExpression($pattern, '/unix/absolute.php');
    }
}
