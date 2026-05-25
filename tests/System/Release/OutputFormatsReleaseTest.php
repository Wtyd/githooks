<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * Release tests for the structured output formats added in 3.2:
 * JSON v2, JUnit, Code Climate, SARIF, plus the `--output=PATH` target
 * and the per-payload contracts (relative paths, executionMode, pretty
 * printing). Progress emission and dashboard fallback live in
 * {@see ProgressOutputReleaseTest}.
 *
 * @group release
 */
class OutputFormatsReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function json_v2_schema_includes_enriched_fields_per_job(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true', 'paths' => ['src']],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(2, $decoded['version']);
        $this->assertArrayHasKey('executionMode', $decoded);
        $this->assertArrayHasKey('passed', $decoded);
        $this->assertArrayHasKey('failed', $decoded);
        $this->assertArrayHasKey('skipped', $decoded);

        $job = $decoded['jobs'][0];
        $this->assertSame('ok_job', $job['name']);
        $this->assertSame('custom', $job['type']);
        $this->assertSame(0, $job['exitCode']);
        $this->assertSame(['src'], $job['paths']);
        $this->assertFalse($job['skipped']);
        $this->assertNull($job['skipReason']);
        $this->assertFalse($job['fixApplied']);
    }

    /** @test */
    public function codeclimate_format_emits_valid_json_array_to_stdout(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded, 'Code Climate output must be a JSON array');
    }

    /** @test */
    public function sarif_format_emits_valid_2_1_0_report_to_stdout(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs([
                'ok_job' => ['type' => 'custom', 'script' => 'true'],
            ]);

        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.1.0', $decoded['version'] ?? null);
        $this->assertArrayHasKey('runs', $decoded);
        $this->assertArrayHasKey('$schema', $decoded);
    }

    /** @test */
    public function json_executionMode_reflects_the_cli_flag(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $cmd = "$this->githooks flow qa --format=json --config=$this->configPath 2>/dev/null";

        $full = json_decode((string) shell_exec($cmd), true);
        $fast = json_decode((string) shell_exec(str_replace('--format', '--fast --format', $cmd)), true);
        $branch = json_decode((string) shell_exec(str_replace('--format', '--fast-branch --format', $cmd)), true);

        $this->assertSame('full', $full['executionMode']);
        $this->assertSame('fast', $fast['executionMode']);
        $this->assertSame('fast-branch', $branch['executionMode']);
    }

    /** @test */
    public function codeclimate_location_path_is_relative_to_cwd(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=codeclimate --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'phpcs should have emitted at least one issue');

        foreach ($decoded as $issue) {
            $path = $issue['location']['path'];
            $this->assertStringStartsNotWith('/', $path, "location.path must be relative, got: $path");
        }
    }

    /** @test */
    public function sarif_artifactLocation_uri_is_relative_to_cwd(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        file_put_contents(
            "$srcDir/Bad.php",
            "<?php\nclass Bad { public \$x; }\n"
        );

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_job']]])
            ->setV3Jobs([
                'phpcs_job' => [
                    'type'     => 'phpcs',
                    'standard' => 'PSR12',
                    'paths'    => [$srcDir],
                ],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --format=sarif --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $sawIssue = false;
        foreach ($decoded['runs'] as $run) {
            foreach ($run['results'] ?? [] as $result) {
                foreach ($result['locations'] ?? [] as $location) {
                    $uri = $location['physicalLocation']['artifactLocation']['uri'] ?? '';
                    $this->assertStringStartsNotWith('/', $uri, "artifactLocation.uri must be relative, got: $uri");
                    $sawIssue = true;
                }
            }
        }
        $this->assertTrue($sawIssue, 'SARIF report should contain at least one result with a location');
    }

    /** @test */
    public function output_flag_writes_payload_to_file_for_each_structured_format(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['ok_job']]])
            ->setV3Jobs(['ok_job' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $targets = [
            'json'        => self::TESTS_PATH . '/qa.json',
            'junit'       => self::TESTS_PATH . '/qa.xml',
            'codeclimate' => self::TESTS_PATH . '/qa-cc.json',
            'sarif'       => self::TESTS_PATH . '/qa.sarif',
        ];

        foreach ($targets as $format => $path) {
            @unlink($path);
            shell_exec("$this->githooks flow qa --format=$format --output=$path --config=$this->configPath 2>/dev/null");
            $this->assertFileExists($path, "--output=PATH should create the file for format=$format");
            $this->assertNotSame('', (string) file_get_contents($path));
        }
    }

    /** @test */
    public function junit_skipped_element_is_emitted_for_skipped_jobs(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job']]])
            ->setV3Jobs([
                'fail_job'  => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job' => ['type' => 'custom', 'script' => 'echo never'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        $output = (string) shell_exec(
            "$this->githooks flow qa --fail-fast --format=junit --config=$this->configPath 2>/dev/null"
        );

        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<skipped', $output);
        $this->assertStringContainsString('never_job', $output);
    }

    /** @test */
    public function fail_fast_cancelled_jobs_appear_as_skipped_in_json(): void
    {
        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['fail_job', 'never_job', 'also_never_job']]])
            ->setV3Jobs([
                'fail_job'        => ['type' => 'custom', 'script' => 'exit 1'],
                'never_job'       => ['type' => 'custom', 'script' => 'echo never'],
                'also_never_job'  => ['type' => 'custom', 'script' => 'echo also'],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks flow qa --fail-fast --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);

        $names = array_column($decoded['jobs'], 'name');
        $this->assertSame(['fail_job', 'never_job', 'also_never_job'], $names, 'jobs[] must contain the full plan');

        $skipped = array_values(array_filter($decoded['jobs'], fn($j) => $j['skipped'] === true));
        $this->assertCount(2, $skipped);
        foreach ($skipped as $job) {
            $this->assertSame('skipped by fail-fast', $job['skipReason']);
        }

        $this->assertSame(2, $decoded['skipped']);
    }

    /** @test */
    public function junit_failure_pretty_prints_phpstan_compact_json_output(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        $phpstanFile = $this->phpFileBuilder->buildWithErrors([\Tests\Utils\PhpFileBuilder::PHPSTAN]);
        file_put_contents("$srcDir/Bad.php", $phpstanFile);

        $junitPath = self::TESTS_PATH . '/qa.junit.xml';
        $codeclimatePath = self::TESTS_PATH . '/qa.cc.json';
        @unlink($junitPath);
        @unlink($codeclimatePath);

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => [$srcDir]],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        // structuredFormat must be active so phpstan emits JSON. Triggered via
        // --report-codeclimate (typical GitLab pipeline shape: JUnit + CC
        // generated together).
        shell_exec(
            "$this->githooks flow qa --format=junit --output=$junitPath"
            . " --report-codeclimate=$codeclimatePath --config=$this->configPath 2>/dev/null"
        );

        $this->assertFileExists($junitPath);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($junitPath), 'junit output must be valid XML');

        $failures = $dom->getElementsByTagName('failure');
        $this->assertGreaterThan(0, $failures->length, 'phpstan errors must produce a <failure> element');

        $failureText = $failures->item(0)->textContent;
        $bodyNewlines = substr_count(trim($failureText), "\n");
        $this->assertGreaterThan(
            5,
            $bodyNewlines,
            'phpstan JSON inside <failure> must be pretty-printed (>5 newlines); got ' . $bodyNewlines
        );

        $jsonEnd = strrpos($failureText, '}');
        $jsonOnly = substr($failureText, 0, $jsonEnd + 1);
        $decoded = json_decode(trim($jsonOnly), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('files', $decoded);

        @unlink($junitPath);
        @unlink($codeclimatePath);
    }
}
