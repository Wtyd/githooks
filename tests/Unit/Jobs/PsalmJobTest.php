<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PsalmJob;

class PsalmJobTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    /** @var string[] */
    private array $dirs = [];

    protected function tearDown(): void
    {
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
    public function cache_paths_default_to_psalm_cache_when_config_arg_is_absent()
    {
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', ['paths' => ['src']]));

        $this->assertSame(['.psalm/cache/'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_default_when_config_file_missing()
    {
        $sandbox = $this->mkSandbox();
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $sandbox . '/missing.xml',
        ]));

        $this->assertSame(['.psalm/cache/'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_read_cache_directory_attribute_resolved_relative_to_xml()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/psalm.xml', <<<'XML'
<?xml version="1.0"?>
<psalm cacheDirectory="storage/psalm-cache" findUnusedCode="false">
    <projectFiles>
        <directory name="src"/>
    </projectFiles>
</psalm>
XML);

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([$sandbox . DIRECTORY_SEPARATOR . 'storage/psalm-cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_use_absolute_cache_directory_verbatim()
    {
        $sandbox = $this->mkSandbox();
        $absolute = '/var/cache/psalm';
        $config = $this->writeFile($sandbox . '/psalm.xml', <<<XML
<?xml version="1.0"?>
<psalm cacheDirectory="$absolute" findUnusedCode="false">
    <projectFiles><directory name="src"/></projectFiles>
</psalm>
XML);

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame([$absolute], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_default_when_cache_directory_attribute_is_empty()
    {
        // Adversarial: explicit empty attribute. Don't return '' — fall back.
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/psalm.xml', '<?xml version="1.0"?><psalm cacheDirectory="" findUnusedCode="false"/>');

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['.psalm/cache/'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_trim_whitespace_around_cache_directory_attribute()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/psalm.xml', '<?xml version="1.0"?><psalm cacheDirectory="  /var/cache  " findUnusedCode="false"/>');

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['/var/cache'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_default_when_xml_is_malformed()
    {
        // Adversarial: simplexml_load_file returns false; we must not crash.
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/psalm-bad.xml', '<?xml version="1.0"?><psalm cacheDirectory="x" not-closed');

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['.psalm/cache/'], $job->getCachePaths());
    }

    /** @test */
    public function cache_paths_default_when_xml_lacks_cache_directory_attribute()
    {
        $sandbox = $this->mkSandbox();
        $config = $this->writeFile($sandbox . '/psalm.xml', <<<'XML'
<?xml version="1.0"?>
<psalm findUnusedCode="false">
    <projectFiles><directory name="src"/></projectFiles>
</psalm>
XML);

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', [
            'paths'  => ['src'],
            'config' => $config,
        ]));

        $this->assertSame(['.psalm/cache/'], $job->getCachePaths());
    }

    private function mkSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/psalm-job-' . uniqid('', true);
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

    // ========================================================================
    // Mutation testing Tier 3 — pin the composite guards on the `config` arg
    // and the xml-validity check, plus the ^anchor on the absolute-path regex
    // in resolveRelativeToConfig.
    // ========================================================================

    /**
     * @test
     *
     * Kills:
     *   - L45 LogicalAnd `!empty($config) && is_file($config) && is_readable($config)`
     *
     * @dataProvider configGuardScenarios
     */
    public function config_arg_composite_guard_falls_back_when_any_check_fails(
        $configValue,
        ?string $writeWithMode,
        string $scenario
    ): void {
        $sandbox = $this->mkSandbox();
        $args = ['paths' => ['src']];

        if ($configValue === '__valid__') {
            $config = $this->writeFile($sandbox . '/psalm.xml', <<<'XML'
<?xml version="1.0"?>
<psalm cacheDirectory="from-config"></psalm>
XML);
            $args['config'] = $config;
        } elseif ($configValue === '__unreadable__') {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('root bypasses chmod permission checks');
            }
            $config = $this->writeFile($sandbox . '/psalm.xml', <<<'XML'
<?xml version="1.0"?>
<psalm cacheDirectory="must-not-be-used"></psalm>
XML);
            chmod($config, 0000);
            $args['config'] = $config;
        } else {
            $args['config'] = $configValue;
        }

        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', $args));

        try {
            if ($configValue === '__valid__') {
                $this->assertSame(
                    [$sandbox . DIRECTORY_SEPARATOR . 'from-config'],
                    $job->getCachePaths(),
                    $scenario
                );
            } else {
                $this->assertSame(['.psalm/cache/'], $job->getCachePaths(), $scenario);
            }
        } finally {
            if ($configValue === '__unreadable__' && isset($config)) {
                chmod($config, 0644);
            }
        }
    }

    /** @return array<string, array{mixed, ?string, string}> */
    public function configGuardScenarios(): array
    {
        return [
            'empty config → empty() short-circuits' => ['', null, '!empty branch'],
            'non-existent file → is_file rejects'   => ['/no/such/psalm.xml', null, 'is_file branch'],
            'unreadable file → is_readable rejects' => ['__unreadable__', null, 'is_readable branch'],
            'valid file → all four operands accept' => ['__valid__', null, 'happy path'],
        ];
    }

    /**
     * @test
     *
     * Kills:
     *   - L50 LogicalAnd `$xml !== false && isset($xml['cacheDirectory'])`
     *
     * Drive the two failure cases of the AND independently:
     *  - malformed XML → $xml === false: fallback (kills the right-hand
     *    isset that would now be skipped via short-circuit).
     *  - XML present but no cacheDirectory attribute: fallback (kills the
     *    left-hand !== false would always pass but isset would not).
     */
    public function xml_validity_and_cache_directory_attribute_must_both_be_present(): void
    {
        $sandbox = $this->mkSandbox();

        // Case A — malformed XML.
        $bad = $this->writeFile($sandbox . '/bad.xml', '<psalm cacheDirectory="x" without-closing');
        $jobA = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', ['paths' => ['src'], 'config' => $bad]));
        $this->assertSame(['.psalm/cache/'], $jobA->getCachePaths(), 'malformed XML must fall back to default');

        // Case B — valid XML without cacheDirectory.
        $noAttr = $this->writeFile($sandbox . '/no-attr.xml', <<<'XML'
<?xml version="1.0"?>
<psalm errorLevel="3"></psalm>
XML);
        $jobB = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', ['paths' => ['src'], 'config' => $noAttr]));
        $this->assertSame(['.psalm/cache/'], $jobB->getCachePaths(), 'missing cacheDirectory must fall back to default');
    }

    /**
     * @test
     *
     * Kills:
     *   - L62 PregMatchRemoveCaret `#^[A-Za-z]:[\\\\/]#` → `#[A-Za-z]:[\\\\/]#`
     *
     * `xyzC:/path` is a relative path that ACCIDENTALLY contains the
     * Windows drive-prefix pattern in the middle. With the caret intact,
     * the regex anchors at position 0 and the path is treated as
     * relative — `dirname($config) . SEP . path` is prepended. Without
     * the caret, the mutant treats the path as absolute and returns it
     * unchanged, leaking a wrong cache path to the cache contract.
     */
    public function resolve_relative_to_config_keeps_anchor_for_windows_pattern(): void
    {
        $job = new PsalmJob(new JobConfiguration('psalm_src', 'psalm', ['paths' => ['src']]));
        $ref = new \ReflectionMethod($job, 'resolveRelativeToConfig');
        $ref->setAccessible(true);

        $this->assertSame(
            '/etc' . DIRECTORY_SEPARATOR . 'xyzC:/path',
            $ref->invoke($job, 'xyzC:/path', '/etc/psalm.xml'),
            'PregMatchRemoveCaret would treat xyzC:/path as absolute'
        );

        // Companion: properly-anchored Windows-absolute paths still pass through.
        $this->assertSame(
            'D:\\cache',
            $ref->invoke($job, 'D:\\cache', 'C:\\projects\\psalm.xml'),
            'Windows-anchored path is absolute and returned verbatim'
        );

        // Companion: Unix absolute path returns verbatim.
        $this->assertSame(
            '/abs/cache',
            $ref->invoke($job, '/abs/cache', '/etc/psalm.xml'),
            'unix absolute path returned verbatim'
        );
    }
}
