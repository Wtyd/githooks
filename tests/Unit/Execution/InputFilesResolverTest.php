<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\Exception\InputFilesException;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Execution\InputFilesResolver;
use Wtyd\GitHooks\Hooks\PatternMatcher;
use Wtyd\GitHooks\Utils\FileUtils;

/**
 * Spec coverage: spec-design-files-flag.md REQ-001..036, AC-001..086.
 */
class InputFilesResolverTest extends TestCase
{
    private string $tmpDir;

    private InputFilesResolver $resolver;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/githooks-input-files-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->resolver = new InputFilesResolver(new FileUtils(), new PatternMatcher());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeFile(string $name): string
    {
        $abs = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, '<?php');
        return $abs;
    }

    /** @test */
    public function csv_files_are_parsed_trimmed_and_deduplicated(): void
    {
        $a = $this->makeFile('a.php');
        $b = $this->makeFile('b.php');

        $resolution = $this->resolver->resolve("$a , $b , $a", null, null, $this->tmpDir);

        $this->assertSame(InputFilesResolution::SOURCE_CLI, $resolution->getSource());
        $this->assertNull($resolution->getSourcePath());
        $this->assertCount(2, $resolution->getValid());
        $this->assertSame(2, $resolution->getTotalProvided());
        $this->assertEmpty($resolution->getInvalid());
    }

    /** @test */
    public function relative_paths_resolve_against_cwd(): void
    {
        $this->makeFile('src/User.php');

        $resolution = $this->resolver->resolve('src/User.php', null, null, $this->tmpDir);

        $this->assertCount(1, $resolution->getValid());
        $valid = $resolution->getValid();
        $this->assertStringEndsWith('src/User.php', $valid[0]);
    }

    /** @test */
    public function absolute_paths_are_used_verbatim(): void
    {
        $abs = $this->makeFile('a.php');

        $resolution = $this->resolver->resolve($abs, null, null, '/never/used');

        $this->assertCount(1, $resolution->getValid());
    }

    /** @test */
    public function mutually_exclusive_files_and_files_from_throws(): void
    {
        $manifest = $this->tmpDir . '/list.txt';
        file_put_contents($manifest, "a.php\n");

        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('--files and --files-from are mutually exclusive');

        $this->resolver->resolve('a.php', $manifest, null, $this->tmpDir);
    }

    /** @test */
    public function exclude_pattern_without_input_throws(): void
    {
        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('--exclude-pattern requires --files or --files-from');

        $this->resolver->resolve(null, null, '**/*Test.php', $this->tmpDir);
    }

    /** @test */
    public function empty_files_csv_throws_empty_input(): void
    {
        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('no input files provided');

        $this->resolver->resolve('  ,  , ', null, null, $this->tmpDir);
    }

    /** @test */
    public function manifest_missing_throws(): void
    {
        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage("file 'missing.txt' does not exist");

        $this->resolver->resolve(null, 'missing.txt', null, $this->tmpDir);
    }

    /** @test */
    public function manifest_strips_bom_and_warns(): void
    {
        $manifest = $this->tmpDir . '/list.txt';
        $a = $this->makeFile('a.php');
        file_put_contents($manifest, "\xEF\xBB\xBF$a\n");

        $resolution = $this->resolver->resolve(null, $manifest, null, $this->tmpDir);

        $this->assertTrue($resolution->isBomDetected());
        $this->assertCount(1, $resolution->getValid());
    }

    /** @test */
    public function manifest_handles_crlf_blanks_and_comments(): void
    {
        $a = $this->makeFile('a.php');
        $b = $this->makeFile('b.php');
        $manifest = $this->tmpDir . '/list.txt';
        file_put_contents($manifest, "# header\r\n$a\r\n\r\n  # nested comment\r\n$b\r\n");

        $resolution = $this->resolver->resolve(null, $manifest, null, $this->tmpDir);

        $this->assertCount(2, $resolution->getValid());
        $this->assertSame(2, $resolution->getTotalProvided());
        $this->assertSame(InputFilesResolution::SOURCE_FILES_FROM, $resolution->getSource());
        $this->assertSame($manifest, $resolution->getSourcePath());
    }

    /** @test */
    public function empty_manifest_throws_empty_input(): void
    {
        $manifest = $this->tmpDir . '/list.txt';
        file_put_contents($manifest, "# only comments\n\n");

        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('no input files provided');

        $this->resolver->resolve(null, $manifest, null, $this->tmpDir);
    }

    /** @test */
    public function invalid_paths_are_collected_and_warned(): void
    {
        $a = $this->makeFile('a.php');

        $resolution = $this->resolver->resolve("$a,ghost.php", null, null, $this->tmpDir);

        $this->assertSame(['ghost.php'], $resolution->getInvalid());
        $this->assertCount(1, $resolution->getValid());
    }

    /** @test */
    public function all_invalid_throws_all_invalid(): void
    {
        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('all input files are invalid');

        $this->resolver->resolve('ghost1.php,ghost2.php', null, null, $this->tmpDir);
    }

    /** @test */
    public function directory_is_expanded_recursively_with_extensions(): void
    {
        $this->makeFile('src/User.php');
        $this->makeFile('src/Generated/Schema.php');
        $this->makeFile('src/notes.txt');

        $resolution = $this->resolver->resolve('src', null, null, $this->tmpDir);

        $files = $resolution->getValid();
        $this->assertCount(2, $files);
        foreach ($files as $f) {
            $this->assertStringEndsWith('.php', $f);
        }
    }

    /** @test */
    public function exclude_pattern_filters_post_expansion(): void
    {
        $this->makeFile('src/User.php');
        $this->makeFile('src/Generated/Schema.php');

        $resolution = $this->resolver->resolve('src', null, 'src/Generated/**', $this->tmpDir);

        $this->assertCount(1, $resolution->getValid());
        $this->assertCount(1, $resolution->getExcluded());
        $this->assertSame(['src/Generated/**'], $resolution->getExcludedPatterns());
        $this->assertTrue($resolution->hasExcludePatterns());
    }

    /** @test */
    public function exclude_pattern_eliminating_all_throws(): void
    {
        $this->makeFile('src/User.php');

        $this->expectException(InputFilesException::class);
        $this->expectExceptionMessage('--exclude-pattern eliminated all input files');

        $this->resolver->resolve('src', null, 'src/**', $this->tmpDir);
    }

    /** @test */
    public function exclude_pattern_with_no_match_is_silent(): void
    {
        $a = $this->makeFile('src/User.php');

        $resolution = $this->resolver->resolve($a, null, '**/*Test.php', $this->tmpDir);

        $this->assertCount(1, $resolution->getValid());
        $this->assertEmpty($resolution->getExcluded());
        $this->assertTrue($resolution->hasExcludePatterns());
        $this->assertSame($resolution->getTotalValid(), $resolution->getTotalAfterExclude());
    }

    /** @test */
    public function multiple_exclude_patterns_use_or_logic(): void
    {
        $this->makeFile('tests/UserTest.php');
        $this->makeFile('database/migrations/0001_init.php');
        $this->makeFile('src/User.php');

        $resolution = $this->resolver->resolve(
            'src,tests,database',
            null,
            '**/*Test.php,database/migrations/**',
            $this->tmpDir
        );

        $this->assertCount(1, $resolution->getValid());
        $this->assertCount(2, $resolution->getExcluded());
    }

    /** @test */
    public function source_path_is_stored_for_files_from(): void
    {
        $manifest = $this->tmpDir . '/list.txt';
        $a = $this->makeFile('a.php');
        file_put_contents($manifest, $a . "\n");

        $resolution = $this->resolver->resolve(null, $manifest, null, $this->tmpDir);

        $this->assertSame($manifest, $resolution->getSourcePath());
        $this->assertSame(InputFilesResolution::SOURCE_FILES_FROM, $resolution->getSource());
    }

    /** @test */
    public function deduplication_keeps_first_occurrence(): void
    {
        $a = $this->makeFile('a.php');

        $resolution = $this->resolver->resolve("$a,$a,$a", null, null, $this->tmpDir);

        $this->assertSame(1, $resolution->getTotalProvided());
        $this->assertCount(1, $resolution->getValid());
    }
}
