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
}
