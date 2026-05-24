<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpunitJob;

class PhpunitJobTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    /** @var string[] */
    private array $dirs = [];

    private ?string $cwdBefore = null;

    protected function tearDown(): void
    {
        if ($this->cwdBefore !== null) {
            chdir($this->cwdBefore);
            $this->cwdBefore = null;
        }
        foreach ($this->paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        foreach ($this->dirs as $d) {
            if (is_dir($d)) {
                @rmdir($d);
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function cache_paths_default_when_no_phpunit_xml_present_in_cwd()
    {
        $sandbox = $this->mkSandbox();
        $this->cwdBefore = getcwd() ?: null;
        chdir($sandbox);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', ['paths' => ['tests']]));

        $this->assertSame(['.phpunit.result.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_read_cache_result_file_attribute_from_explicit_configuration()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile=".cache/phpunit.cache" colors="true">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . '.cache/phpunit.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_read_cache_directory_attribute_for_phpunit_10()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheDirectory=".phpunit.cache" colors="true">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths' => ['tests'],
            'config' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . '.phpunit.cache'], $job->getCachePaths());
    }


    /** @test */
    public function cache_paths_pick_phpunit_xml_from_cwd_when_no_explicit_config()
    {
        $sandbox = $this->mkSandbox();
        $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="result.cache">
    <testsuites/>
</phpunit>
XML);
        $this->cwdBefore = getcwd() ?: null;
        chdir($sandbox);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', ['paths' => ['tests']]));

        $this->assertSame(['.' . DIRECTORY_SEPARATOR . 'result.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_fall_back_to_phpunit_xml_dist_when_phpunit_xml_missing()
    {
        $sandbox = $this->mkSandbox();
        $this->writeFile($sandbox . '/phpunit.xml.dist', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="dist.cache">
    <testsuites/>
</phpunit>
XML);
        $this->cwdBefore = getcwd() ?: null;
        chdir($sandbox);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', ['paths' => ['tests']]));

        $this->assertSame(['.' . DIRECTORY_SEPARATOR . 'dist.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_directory_attribute_wins_over_cache_result_file_when_both_present()
    {
        // Adversarial: in PHPUnit 10+, cacheDirectory replaced cacheResultFile
        // (deprecated). When both are declared, PHPUnit itself uses
        // cacheDirectory and ignores cacheResultFile — we mirror that.
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="legacy.cache" cacheDirectory="modern.cache">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'modern.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_result_file_is_used_when_cache_directory_is_empty()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="legacy.cache" cacheDirectory="">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'legacy.cache'], $job->getCachePaths());
    }

    /** @test */
    public function malformed_phpunit_xml_does_not_crash_falls_back_to_default()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', '<?xml version="1.0"?><phpunit cacheResultFile="x" not-closed');

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame(['.phpunit.result.cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_default_when_xml_has_no_cache_attributes()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit colors="true">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame(['.phpunit.result.cache'], $job->getCachePaths());
    }

    /**
     * @test
     *
     * XML attribute with surrounding whitespace: without trim, the resolved
     * path would include the leading/trailing spaces and the relative-path
     * resolver would build `dirname($config) . DIRECTORY_SEPARATOR . "  modern.cache  "`
     * — not a file the user would ever expect.
     */
    public function cache_directory_attribute_is_trimmed_of_surrounding_whitespace(): void
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheDirectory="  modern.cache  ">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'modern.cache'], $job->getCachePaths());
    }

    /**
     * @test
     *
     */
    public function cache_result_file_attribute_is_trimmed_of_surrounding_whitespace(): void
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="   legacy.cache   ">
    <testsuites/>
</phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'legacy.cache'], $job->getCachePaths());
    }

    /**
     * @test
     *
     *     → `$args['config'] ?? $args['configuration'] ?? ''`
     *
     * When BOTH keys point to readable config files, `configuration` (long
     * form, matches PHPUnit's own CLI flag `--configuration`) must win. The
     * swapped coalesce would silently fall back to `config` (short form
     * `-c`), which is the wrong precedence.
     */
    public function configuration_arg_wins_over_short_config_when_both_present(): void
    {
        $sandbox = $this->mkSandbox();
        $long = $this->writeFile($sandbox . '/long.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheDirectory="long.cache"><testsuites/></phpunit>
XML);
        $short = $this->writeFile($sandbox . '/short.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheDirectory="short.cache"><testsuites/></phpunit>
XML);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', [
            'paths'         => ['tests'],
            'configuration' => $long,
            'config'        => $short,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'long.cache'], $job->getCachePaths());
    }

    /**
     * @test
     *
     * Decision table: each `&&` mutated to `||` produces a different
     * accept/reject behaviour. We pin the four failure cases that anchor
     * each `&&` and one success case.
     *
     * @dataProvider locateConfigGuardScenarios
     */
    public function locate_config_file_composite_guard_rejects_invalid_explicit_args(
        $explicitValue,
        bool $createFile,
        bool $expectFallbackToDefault,
        string $scenario
    ): void {
        $sandbox = $this->mkSandbox();
        $this->cwdBefore = getcwd() ?: null;
        chdir($sandbox);

        $args = ['paths' => ['tests']];
        if ($explicitValue !== '__omit__') {
            // When the caller supplies an explicit path, optionally create
            // the file on disk to flip the is_file/is_readable branches.
            if (is_string($explicitValue) && $createFile) {
                $this->writeFile($sandbox . '/explicit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheDirectory="from-explicit.cache"><testsuites/></phpunit>
XML);
                $args['configuration'] = $sandbox . '/explicit.xml';
            } else {
                $args['configuration'] = $explicitValue;
            }
        }

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', $args));

        if ($expectFallbackToDefault) {
            $this->assertSame(['.phpunit.result.cache'], $job->getCachePaths(), $scenario);
        } else {
            $this->assertSame(
                [$sandbox . DIRECTORY_SEPARATOR . 'from-explicit.cache'],
                $job->getCachePaths(),
                $scenario
            );
        }
    }

    /** @return array<string, array{mixed, bool, bool, string}> */
    public function locateConfigGuardScenarios(): array
    {
        return [
            'non-string explicit (array) → fallback' => [
                ['nested'], false, true,
                'array silently coerces in `?? ""` chain; is_string guard rejects → fallback',
            ],
            'empty-string explicit → fallback' => [
                '', false, true,
                'explicit !== "" guard rejects empty',
            ],
            'explicit non-existent file → fallback' => [
                '/no/such/file.xml', false, true,
                'is_file guard rejects',
            ],
            'explicit valid file → uses it' => [
                'placeholder-replaced-by-real-tmpfile', true, false,
                'all four guards pass',
            ],
        ];
    }

    /**
     * @test
     *
     * Create a phpunit.xml that exists but whose permissions strip read
     * access. With the mutant `||`, is_file returns true so the OR short-
     * circuits to true and locateConfigFile returns the path; PHPUnit then
     * fails to load. The original `&&` correctly rejects the unreadable
     * candidate and falls through to phpunit.xml.dist (or default).
     */
    public function unreadable_phpunit_xml_in_cwd_is_skipped_in_favour_of_fallback(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses chmod permission checks');
        }

        $sandbox = $this->mkSandbox();
        $unreadable = $this->writeFile($sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="must-not-be-used.cache"><testsuites/></phpunit>
XML);
        chmod($unreadable, 0000);

        $this->writeFile($sandbox . '/phpunit.xml.dist', <<<'XML'
<?xml version="1.0"?>
<phpunit cacheResultFile="fallback.cache"><testsuites/></phpunit>
XML);

        $this->cwdBefore = getcwd() ?: null;
        chdir($sandbox);

        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', ['paths' => ['tests']]));

        try {
            $this->assertSame(
                ['.' . DIRECTORY_SEPARATOR . 'fallback.cache'],
                $job->getCachePaths(),
                'unreadable phpunit.xml must be skipped, falling through to phpunit.xml.dist'
            );
        } finally {
            // Restore permissions so tearDown can unlink the file.
            chmod($unreadable, 0644);
        }
    }

    /**
     * @test
     *
     * Decision table for `resolveRelativeToConfig`. The method must return
     * the path UNCHANGED for absolute paths (Unix `/abs`, Windows `C:\…`)
     * and the empty string, and must PREPEND `dirname($configFile) . SEP`
     * for relative paths. Two adversarial inputs catch the IncrementInteger
     * and PregMatchRemoveCaret mutants:
     *   - `a/b` (first char NOT '/', second char IS '/'): with $path[1] the
     *     mutant treats it as absolute; with the correct $path[0] it stays
     *     relative.
     *   - `xyzC:/path` (Windows pattern but not anchored to start): without
     *     the `^` anchor the mutant treats it as Windows-absolute.
     *
     * @dataProvider resolveRelativeScenarios
     */
    public function resolve_relative_to_config_decision_table(
        string $path,
        string $configFile,
        string $expected,
        string $scenario
    ): void {
        $job = new PhpunitJob(new JobConfiguration('phpunit_src', 'phpunit', ['paths' => ['tests']]));
        $ref = new \ReflectionMethod($job, 'resolveRelativeToConfig');
        $ref->setAccessible(true);

        $this->assertSame($expected, $ref->invoke($job, $path, $configFile), $scenario);
    }

    /** @return array<string, array{string, string, string, string}> */
    public function resolveRelativeScenarios(): array
    {
        return [
            'empty path returns as-is' => [
                '', '/etc/phpunit.xml', '',
                'empty short-circuits — no dirname prepended',
            ],
            'unix absolute path returns as-is' => [
                '/abs/cache', '/etc/phpunit.xml', '/abs/cache',
                'first char "/" — absolute',
            ],
            'windows-style absolute returns as-is' => [
                'C:\\Users\\cache', 'C:\\projects\\phpunit.xml', 'C:\\Users\\cache',
                'preg_match with ^anchor accepts Windows drive prefix',
            ],
            'windows-forward-slash variant returns as-is' => [
                'D:/Users/cache', 'C:\\projects\\phpunit.xml', 'D:/Users/cache',
                'regex character class [\\\\/] matches forward slash too',
            ],
            'relative path is prepended with config dir' => [
                'rel/cache', '/etc/phpunit.xml', '/etc' . DIRECTORY_SEPARATOR . 'rel/cache',
                'no leading "/", no drive prefix — prepend dirname',
            ],
            'relative path with second char slash stays relative' => [
                'a/b', '/etc/phpunit.xml', '/etc' . DIRECTORY_SEPARATOR . 'a/b',
                'kills $path[0] → $path[1] mutant: the SECOND char is "/" but the FIRST is not',
            ],
            'windows pattern in middle stays relative (anchor matters)' => [
                'xyzC:/path', '/etc/phpunit.xml', '/etc' . DIRECTORY_SEPARATOR . 'xyzC:/path',
                'kills PregMatchRemoveCaret: without ^ the regex would match anywhere',
            ],
        ];
    }

    private function mkSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/phpunit-job-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->dirs[] = $dir;
        return $dir;
    }

    private function writeFile(string $path, string $content): string
    {
        file_put_contents($path, $content);
        $this->paths[] = $path;
        return $path;
    }
}
