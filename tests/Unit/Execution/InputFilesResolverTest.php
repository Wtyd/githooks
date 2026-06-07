<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\Exception\InputFilesException;
use Wtyd\GitHooks\Execution\InputFilesResolution;
use Wtyd\GitHooks\Execution\InputFilesResolver;
use Wtyd\GitHooks\Hooks\PatternMatcher;
use Wtyd\GitHooks\Utils\FileUtils;

/**
 * Spec coverage: spec-design-files-flag.md REQ-001..036, AC-001..086.
 */
class InputFilesResolverTest extends UnitTestCase
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

    /**
     * @test
     * Mata el mutante Continue_ → Break_ en línea 136 (`parseCsv`): un
     * duplicado en medio de la lista no debe abortar el parseo de los
     * elementos posteriores.
     */
    public function csv_dedupe_does_not_abort_processing_of_remaining_items(): void
    {
        $a = $this->makeFile('a.php');
        $b = $this->makeFile('b.php');

        // Orden: a, a (duplicado), b — el duplicado es el segundo, b es nuevo.
        $resolution = $this->resolver->resolve("$a , $a , $b", null, null, $this->tmpDir);

        $valid = $resolution->getValid();
        $this->assertCount(2, $valid);
        $this->assertContains($a, $valid);
        $this->assertContains($b, $valid);
    }

    /**
     * @test
     * Mata el mutante UnwrapTrim en línea 121 (`normaliseScalar`): si el
     * trim desaparece, la ruta del manifest llega con espacios y el
     * fichero no se encuentra. Real lee el manifest y procesa su contenido.
     */
    public function manifest_path_with_surrounding_whitespace_is_trimmed(): void
    {
        $a = $this->makeFile('a.php');
        $manifest = $this->tmpDir . '/list.txt';
        file_put_contents($manifest, "$a\n");

        $resolution = $this->resolver->resolve(null, "  $manifest  ", null, $this->tmpDir);

        $this->assertCount(1, $resolution->getValid());
        $this->assertSame($manifest, $resolution->getSourcePath());
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

    /**
     * @test
     * Mata el mutante ReturnRemoval en línea 241 (`shapeForUser`): cuando la
     * ruta original es absoluta y CWD es el directorio padre, real devuelve
     * la ruta absoluta tal cual; mutado cae al stripping relativo y devuelve
     * solo el nombre del fichero.
     */
    public function absolute_paths_inside_cwd_keep_their_absolute_form(): void
    {
        $abs = $this->makeFile('foo.php');

        $resolution = $this->resolver->resolve($abs, null, null, $this->tmpDir);

        $valid = $resolution->getValid();
        $this->assertCount(1, $valid);
        $this->assertSame($abs, $valid[0]);
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

    /**
     * @test
     * BUG-8: when --exclude-pattern empties the input list, the resolver no
     * longer throws. It returns a resolution with valid=[] so accelerable jobs
     * get skipped downstream with reason "no input files match its paths"
     * and the flow exits 0 when nothing fails.
     */
    public function exclude_pattern_eliminating_all_returns_empty_resolution(): void
    {
        $this->makeFile('src/User.php');

        $resolution = $this->resolver->resolve('src', null, 'src/**', $this->tmpDir);

        $this->assertSame([], $resolution->getValid());
        $this->assertSame(1, $resolution->getTotalProvided());
        $this->assertSame(0, $resolution->getTotalAfterExclude());
        $this->assertCount(1, $resolution->getExcluded());
        $this->assertTrue($resolution->hasExcludePatterns());
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


    /** @test */
    public function manifest_without_bom_reports_bom_detected_false(): void
    {
        // Kills FalseValue mutant on `$bomDetected = false;` at line 158:
        // mutating to `true` would falsely flag BOM presence on a
        // perfectly clean manifest.
        $manifestPath = $this->tmpDir . '/clean.txt';
        $a = $this->makeFile('a.php');
        file_put_contents($manifestPath, "$a\n");

        $resolution = $this->resolver->resolve(null, $manifestPath, null, $this->tmpDir);

        $this->assertFalse($resolution->isBomDetected());
    }

    /** @test */
    public function windows_drive_letter_paths_are_recognised_as_absolute(): void
    {
        // Kills the LogicalAndSingleSubExprNegation mutant on the
        // ctype_alpha guard in isAbsolute() at line 302: "9:/" must NOT
        // be absolute (digit start), "C:/" must be (alpha start). With
        // the negation, the comparison flips.
        $reflection = new \ReflectionMethod(
            \Wtyd\GitHooks\Execution\InputFilesResolver::class,
            'isAbsolute'
        );
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke($this->resolver, 'C:/test'));
        $this->assertTrue($reflection->invoke($this->resolver, 'C:\\test'));
        $this->assertFalse($reflection->invoke($this->resolver, '9:/test'));
        $this->assertFalse($reflection->invoke($this->resolver, ':/test'));
    }
}
