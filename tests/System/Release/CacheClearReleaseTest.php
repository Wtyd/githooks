<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class CacheClearReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    /** @var string[] Extra files/dirs created in tests, cleaned in tearDown. */
    private array $artifacts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';

        $this->configurationFileBuilder->enableV3Mode();

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_src']]])
            ->setV3Jobs([
                'phpcs_src' => ['type' => 'phpcs', 'paths' => [self::TESTS_PATH . '/src'], 'standard' => 'PSR12'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $path) {
            $this->removeArtifact($path);
        }
        $this->artifacts = [];
        parent::tearDown();
    }

    /** @test */
    public function it_clears_caches_reporting_not_found()
    {
        @unlink('.phpcs.cache');

        passthru("$this->githooks cache:clear --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('not found', $this->getActualOutput());
    }

    /** @test */
    public function it_clears_specific_job_cache()
    {
        file_put_contents('.phpcs.cache', 'fake cache');

        passthru("$this->githooks cache:clear phpcs_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('deleted', $this->getActualOutput());
        $this->assertFileDoesNotExist('.phpcs.cache');
    }

    /**
     * @test
     * Bug origen del usuario: tmpDir vivo en una cadena de includes y
     * referenciado con %currentWorkingDirectory%. Antes del fix, cache:clear
     * caía a /tmp/phpstan (default) y nunca borraba la caché real.
     */
    public function release_phpstan_resolves_tmpdir_through_includes_with_placeholder()
    {
        $this->writeJobConfig([
            'phpstan_src' => [
                'type'   => 'phpstan',
                'config' => self::TESTS_PATH . '/phpstan-ci.neon',
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $this->writeArtifact(self::TESTS_PATH . '/phpstan-base.neon', "parameters:\n    tmpDir: %currentWorkingDirectory%/qa-cache\n");
        $this->writeArtifact(self::TESTS_PATH . '/phpstan-ci.neon', "includes:\n    - phpstan-base.neon\n");

        $cacheDir = getcwd() . '/qa-cache';
        $this->mkArtifactDir($cacheDir);
        file_put_contents("$cacheDir/cache.bin", 'fake');

        passthru("$this->githooks cache:clear phpstan_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    /**
     * @test
     * Adversarial: tmpDir como atributo de un servicio NO es la config de
     * PHPStan. El binario debe ignorarlo y caer al default.
     */
    public function release_phpstan_ignores_tmpdir_inside_services_block()
    {
        $this->writeJobConfig([
            'phpstan_src' => [
                'type'   => 'phpstan',
                'config' => self::TESTS_PATH . '/phpstan-services.neon',
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $neon = "services:\n    -\n        class: My\\Service\n        arguments:\n            tmpDir: '/wrong'\nparameters:\n    level: 8\n";
        $this->writeArtifact(self::TESTS_PATH . '/phpstan-services.neon', $neon);

        passthru("$this->githooks cache:clear phpstan_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringNotContainsString('/wrong', $output);
        $this->assertStringContainsString('phpstan', $output);
    }

    /**
     * @test
     * Matiz UX nuevo: cuando tmpDir trae un placeholder no expandible
     * (%env.X%), el comando reporta "placeholder" en el output.
     */
    public function release_phpstan_surfaces_warning_when_tmpdir_uses_unsupported_placeholder()
    {
        $this->writeJobConfig([
            'phpstan_src' => [
                'type'   => 'phpstan',
                'config' => self::TESTS_PATH . '/phpstan-env.neon',
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $this->writeArtifact(self::TESTS_PATH . '/phpstan-env.neon', "parameters:\n    tmpDir: %env.HOME%/qa-cache\n");

        passthru("$this->githooks cache:clear phpstan_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('placeholder', $this->getActualOutput());
    }

    /**
     * @test
     * Bug 2 audit: cuando phpunit.xml declara cacheDirectory (10+) y
     * cacheResultFile (legacy), gana cacheDirectory. Antes del fix la
     * precedencia estaba invertida.
     */
    public function release_phpunit_cache_directory_wins_over_cache_result_file()
    {
        $this->writeJobConfig([
            'phpunit_src' => [
                'type'          => 'phpunit',
                'configuration' => self::TESTS_PATH . '/phpunit-both.xml',
                'paths'         => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $xml = '<?xml version="1.0"?><phpunit cacheResultFile="legacy.cache" cacheDirectory="modern.cache"><testsuites/></phpunit>';
        $this->writeArtifact(self::TESTS_PATH . '/phpunit-both.xml', $xml);

        $this->writeArtifact(self::TESTS_PATH . '/modern.cache', 'fake-modern');
        $this->writeArtifact(self::TESTS_PATH . '/legacy.cache', 'fake-legacy');

        passthru("$this->githooks cache:clear phpunit_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertFileDoesNotExist(self::TESTS_PATH . '/modern.cache');
        $this->assertFileExists(self::TESTS_PATH . '/legacy.cache');
    }

    /**
     * @test
     * Default fixed: rector usa sys_get_temp_dir().'/rector_cached_files',
     * no '/tmp/rector' (incorrecto + no portable).
     */
    public function release_rector_default_targets_sys_temp_rector_cached_files()
    {
        $this->writeJobConfig([
            'rector_src' => [
                'type'  => 'rector',
                'paths' => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $expected = sys_get_temp_dir() . '/rector_cached_files';
        $this->mkArtifactDir($expected);
        file_put_contents("$expected/cache.bin", 'fake');

        passthru("$this->githooks cache:clear rector_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist($expected);
    }

    /**
     * @test
     * Caso común documentado: cacheDirectory(__DIR__ . '/.cache') resuelve
     * a dirname(rector.php).'/.cache'.
     */
    public function release_rector_regex_resolves_dir_concat()
    {
        $rectorConfig = self::TESTS_PATH . '/rector.php';
        $this->writeArtifact($rectorConfig, "<?php\nreturn function (\$config) { \$config->cacheDirectory(__DIR__ . '/.rector-cache'); };\n");

        $this->writeJobConfig([
            'rector_src' => [
                'type'   => 'rector',
                'config' => $rectorConfig,
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $cacheDir = self::TESTS_PATH . '/.rector-cache';
        $this->mkArtifactDir($cacheDir);
        file_put_contents("$cacheDir/cache.bin", 'fake');

        passthru("$this->githooks cache:clear rector_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    /**
     * @test
     * Escape hatch del último recurso: cuando rector.php usa cacheDirectory
     * con expresión dinámica, el meta-arg cache-dir gana y permite borrar.
     */
    public function release_rector_meta_arg_cache_dir_overrides_unparseable_config()
    {
        $rectorConfig = self::TESTS_PATH . '/rector.php';
        $this->writeArtifact($rectorConfig, "<?php\nreturn function (\$config) { \$config->cacheDirectory(\$dynamicCacheDir); };\n");

        $cacheDir = self::TESTS_PATH . '/forced-cache';
        $this->writeJobConfig([
            'rector_src' => [
                'type'      => 'rector',
                'config'    => $rectorConfig,
                'cache-dir' => $cacheDir,
                'paths'     => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $this->mkArtifactDir($cacheDir);
        file_put_contents("$cacheDir/cache.bin", 'fake');

        passthru("$this->githooks cache:clear rector_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    /**
     * @test
     * Matiz UX accionable: sin meta-arg, el output explica el problema y
     * apunta al `cache-dir` como salida.
     */
    public function release_rector_emits_actionable_warning_when_unparseable_and_no_meta_arg()
    {
        $rectorConfig = self::TESTS_PATH . '/rector.php';
        $this->writeArtifact($rectorConfig, "<?php\nreturn function (\$config) { \$config->cacheDirectory(\$dynamicCacheDir); };\n");

        $this->writeJobConfig([
            'rector_src' => [
                'type'   => 'rector',
                'config' => $rectorConfig,
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        passthru("$this->githooks cache:clear rector_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        $this->assertStringContainsString('could not parse cacheDirectory', $output);
        $this->assertStringContainsString("'cache-dir'", $output);
    }

    /**
     * @test
     * Default fixed: PHPMD ≥ 2.13 escribe en .phpmd.result.cache. Antes el
     * default era .phpmd.cache, así que cache:clear nunca tocaba la real.
     */
    public function release_phpmd_default_is_phpmd_result_cache()
    {
        $this->writeJobConfig([
            'phpmd_src' => [
                'type'  => 'phpmd',
                'paths' => [self::TESTS_PATH . '/src'],
                'rules' => 'unusedcode',
            ],
        ]);

        // Defaults relativos: viven en el cwd que ejecuta cache:clear.
        // Mismo patrón que el test base it_clears_specific_job_cache.
        file_put_contents('.phpmd.result.cache', 'fake-real');
        file_put_contents('.phpmd.cache', 'fake-old-default');

        passthru("$this->githooks cache:clear phpmd_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertFileDoesNotExist('.phpmd.result.cache');
        $this->assertFileExists('.phpmd.cache');
        @unlink('.phpmd.cache');
    }

    /**
     * @test
     * Caso paralelo a Rector: setCacheFile(__DIR__ . '/...') en
     * .php-cs-fixer.php se resuelve y la caché se borra.
     */
    public function release_php_cs_fixer_regex_resolves_set_cache_file_dir_concat()
    {
        $fixerConfig = self::TESTS_PATH . '/.php-cs-fixer.php';
        $this->writeArtifact(
            $fixerConfig,
            "<?php\nreturn (new PhpCsFixer\\Config())->setCacheFile(__DIR__ . '/.fixer.cache');\n"
        );

        $this->writeJobConfig([
            'php_cs_fixer' => [
                'type'   => 'php-cs-fixer',
                'config' => $fixerConfig,
                'paths'  => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $cacheFile = self::TESTS_PATH . '/.fixer.cache';
        $this->writeArtifact($cacheFile, 'fake');

        passthru("$this->githooks cache:clear php_cs_fixer --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertFileDoesNotExist($cacheFile);
    }

    /**
     * @test
     * Aclaración 5: PHPCS aplica last-wins sobre `<arg name="cache">`
     * duplicados, igual que el propio phpcs al cargar el ruleset.
     */
    public function release_phpcs_multiple_cache_args_in_ruleset_last_wins()
    {
        $rulesetPath = self::TESTS_PATH . '/ruleset-multi.xml';
        $xml = "<?xml version=\"1.0\"?>\n<ruleset name=\"Test\">\n    <arg name=\"cache\" value=\"" . self::TESTS_PATH . "/first.cache\"/>\n    <arg name=\"cache\" value=\"" . self::TESTS_PATH . "/second.cache\"/>\n</ruleset>\n";
        $this->writeArtifact($rulesetPath, $xml);

        $this->writeJobConfig([
            'phpcs_src' => [
                'type'     => 'phpcs',
                'standard' => $rulesetPath,
                'paths'    => [self::TESTS_PATH . '/src'],
            ],
        ]);

        $this->writeArtifact(self::TESTS_PATH . '/first.cache', 'fake-first');
        $this->writeArtifact(self::TESTS_PATH . '/second.cache', 'fake-second');

        passthru("$this->githooks cache:clear phpcs_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists(self::TESTS_PATH . '/first.cache');
        $this->assertFileDoesNotExist(self::TESTS_PATH . '/second.cache');
    }

    /**
     * @test
     * Bug 3 audit: meta-arg con whitespace puro no debe pasarse como path
     * literal; cae al default.
     */
    public function release_meta_arg_cache_dir_with_whitespace_falls_back_to_default()
    {
        $this->writeJobConfig([
            'rector_src' => [
                'type'      => 'rector',
                'cache-dir' => '   ',
                'paths'     => [self::TESTS_PATH . '/src'],
            ],
        ]);

        passthru("$this->githooks cache:clear rector_src --config=$this->configPath 2>&1", $exitCode);

        $this->assertEquals(0, $exitCode);
        $output = $this->getActualOutput();
        // Default real de Rector — no '   ' literal en stdout.
        $this->assertStringContainsString('rector_cached_files', $output);
        $this->assertStringNotContainsString(': "   "', $output);
    }

    /**
     * @param array<string, array<string, mixed>> $jobs
     */
    private function writeJobConfig(array $jobs): void
    {
        $this->configurationFileBuilder = new \Tests\Utils\ConfigurationFileBuilder($this->path);
        $this->configurationFileBuilder->enableV3Mode();
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => array_keys($jobs)]])
            ->setV3Jobs($jobs);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());
    }

    private function writeArtifact(string $path, string $content): string
    {
        file_put_contents($path, $content);
        $this->artifacts[] = $path;
        return $path;
    }

    private function mkArtifactDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $this->artifacts[] = $path;
    }

    private function removeArtifact(string $path): void
    {
        if (is_dir($path)) {
            $this->removeDirRecursive($path);
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function removeDirRecursive(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirRecursive($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
