<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\Utils\PhpFileBuilder;
use Tests\ReleaseTestCase;

/**
 * Release tests for multi-report (3.3) and the per-key cascade fix (BUG-20,
 * 3.4): `flows.options.reports` and `--report-X=PATH` flags must produce the
 * expected payloads regardless of the primary `--format`, and the global
 * `reports` declaration must cascade per-key when a flow declares its own
 * `options:` block.
 *
 * BUG-16 (3.2) covered: when `reports.codeclimate` / `reports.sarif` is
 * declared in config or via CLI, the underlying tools must emit JSON so the
 * file-based formatters can parse issues — even without a `--format=codeclimate|sarif`
 * primary output.
 *
 * @group release
 */
class MultiReportReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
        $this->configurationFileBuilder->enableV3Mode();
    }

    /** @test */
    public function bug16_reports_codeclimate_in_config_captures_phpstan_issues_without_format_flag(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        $phpstanFile = $this->phpFileBuilder->buildWithErrors([PhpFileBuilder::PHPSTAN]);
        file_put_contents("$srcDir/Bad.php", $phpstanFile);

        $reportPath = self::TESTS_PATH . '/qa-cc.json';
        @unlink($reportPath);

        $this->configurationFileBuilder
            ->setV3GlobalOptions(['reports' => ['codeclimate' => $reportPath]])
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => [$srcDir]],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        shell_exec("$this->githooks flow qa --config=$this->configPath 2>/dev/null");

        $this->assertFileExists($reportPath);
        $decoded = json_decode((string) file_get_contents($reportPath), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty(
            $decoded,
            'codeclimate report must contain phpstan issues; an empty array means structuredFormat was not propagated'
        );

        $hasPhpstanIssue = false;
        foreach ($decoded as $issue) {
            if (
                ($issue['check_name'] ?? '') === 'phpstan'
                || strpos($issue['check_name'] ?? '', '.') !== false
            ) {
                $hasPhpstanIssue = true;
                break;
            }
        }
        $this->assertTrue($hasPhpstanIssue, 'codeclimate payload must include at least one phpstan issue');

        @unlink($reportPath);
    }

    /** @test */
    public function bug16_reports_codeclimate_in_config_captures_issues_from_all_tool_types(): void
    {
        // Same bug, different surface: pre-fix the bypass affected EVERY tool
        // whose parser depends on JSON tool output (phpcs, phpmd, phpstan,
        // psalm, parallel-lint). Verifies that activating structuredFormat
        // reaches each Job's applyStructuredOutputFormat() through the Phar
        // build, not just phpstan.
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        $multiToolFile = $this->phpFileBuilder->buildWithErrors([
            PhpFileBuilder::PHPCS,
            PhpFileBuilder::PHPMD,
        ]);
        file_put_contents("$srcDir/Bad.php", $multiToolFile);

        $reportPath = self::TESTS_PATH . '/qa-cc.json';
        @unlink($reportPath);

        $this->configurationFileBuilder
            ->setV3GlobalOptions(['reports' => ['codeclimate' => $reportPath]])
            ->setV3Flows(['qa' => ['jobs' => ['phpcs_src', 'phpmd_src']]])
            ->setV3Jobs([
                'phpcs_src' => ['type' => 'phpcs', 'standard' => 'PSR12', 'paths' => [$srcDir]],
                'phpmd_src' => ['type' => 'phpmd', 'paths' => [$srcDir]],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        shell_exec("$this->githooks flow qa --config=$this->configPath 2>/dev/null");

        $this->assertFileExists($reportPath);
        $decoded = json_decode((string) file_get_contents($reportPath), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty(
            $decoded,
            'codeclimate report must contain issues from at least one tool; empty means structuredFormat was not propagated'
        );

        $tools = [];
        foreach ($decoded as $issue) {
            $check = (string) ($issue['check_name'] ?? '');
            if (strpos($check, 'Squiz.') === 0 || strpos($check, 'PSR12.') === 0 || strpos($check, 'Generic.') === 0) {
                $tools['phpcs'] = true;
            }
            if ($check !== '' && strpos($check, '.') === false && $check !== 'phpstan') {
                $tools['phpmd'] = true;
            }
        }
        $this->assertArrayHasKey('phpcs', $tools, 'codeclimate payload must include at least one phpcs issue (Squiz/PSR12/Generic.* prefix)');
        $this->assertArrayHasKey('phpmd', $tools, 'codeclimate payload must include at least one phpmd issue (bare rule name)');

        @unlink($reportPath);
    }

    /** @test */
    public function bug16_cli_report_sarif_captures_phpstan_issues_without_format_flag(): void
    {
        $srcDir = self::TESTS_PATH . '/src';
        @mkdir($srcDir, 0777, true);
        $phpstanFile = $this->phpFileBuilder->buildWithErrors([PhpFileBuilder::PHPSTAN]);
        file_put_contents("$srcDir/Bad.php", $phpstanFile);

        $reportPath = self::TESTS_PATH . '/qa.sarif';
        @unlink($reportPath);

        $this->configurationFileBuilder
            ->setV3Flows(['qa' => ['jobs' => ['phpstan_src']]])
            ->setV3Jobs([
                'phpstan_src' => ['type' => 'phpstan', 'level' => 0, 'paths' => [$srcDir]],
            ]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        shell_exec("$this->githooks flow qa --report-sarif=$reportPath --config=$this->configPath 2>/dev/null");

        $this->assertFileExists($reportPath);
        $decoded = json_decode((string) file_get_contents($reportPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.1.0', $decoded['version'] ?? null);
        $results = $decoded['runs'][0]['results'] ?? [];
        $this->assertNotEmpty(
            $results,
            'SARIF runs[0].results must contain phpstan findings; empty means structuredFormat was not propagated'
        );

        @unlink($reportPath);
    }

    /**
     * BUG-20 (3.4) — `reports` declared at `flows.options` must cascade
     * per-key when a flow declares its own `options:` block. End-to-end
     * check: the SARIF report file must exist after the run.
     *
     * @test
     */
    public function phar_cascades_reports_per_key_when_flow_declares_options(): void
    {
        $reportPath = self::TESTS_PATH . '/qa.sarif';
        @unlink($reportPath);

        $config = [
            'flows' => [
                'options' => ['reports' => ['sarif' => $reportPath]],
                'qa' => [
                    'options' => ['fail-fast' => true],
                    'jobs' => ['noop_job'],
                ],
            ],
            'jobs' => [
                'noop_job' => [
                    'type'            => 'custom',
                    'executable-path' => 'true',
                    'paths'           => ['.'],
                ],
            ],
        ];
        file_put_contents($this->configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        passthru(
            sprintf('%s flow qa --config=%s > /dev/null 2>&1', $this->githooks, $this->configPath),
            $exitCode
        );

        $this->assertSame(0, $exitCode);
        $this->assertFileExists(
            $reportPath,
            'Global reports.sarif must cascade per-key when the flow declares an options block'
        );
        $payload = (string) file_get_contents($reportPath);
        $this->assertNotSame('', $payload, 'SARIF report file must not be empty');
        $this->assertNotNull(json_decode($payload, true), 'SARIF report file must contain valid JSON');

        @unlink($reportPath);
    }
}
