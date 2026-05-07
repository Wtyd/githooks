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
